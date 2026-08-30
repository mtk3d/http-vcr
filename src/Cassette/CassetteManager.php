<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

use Closure;
use DateInterval;
use HttpVcr\Ansi;
use HttpVcr\Environment;
use HttpVcr\EraseTape;
use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\Exception\RecordingNotAllowedException;
use HttpVcr\Exception\StaleCassetteException;
use HttpVcr\Exception\StrictModeViolationException;
use HttpVcr\Hook\HookRegistry;
use HttpVcr\Hook\RedactionHooks;
use HttpVcr\Matching\CompositeMatcher;
use HttpVcr\Matching\Mismatch;
use HttpVcr\Persistence\CassettePersisterInterface;
use HttpVcr\Persistence\SidecarBodies;
use HttpVcr\Persistence\SupportsSessionLocking;
use HttpVcr\RecordMode;
use HttpVcr\RunWarnings;
use HttpVcr\SecretScanner;
use HttpVcr\Serializer\CassetteSerializerInterface;
use HttpVcr\StrictMode;
use Psr\Clock\ClockInterface;

/**
 * One cassette file for the length of a session: opening it, deciding whether this run may
 * record into it, handing out matching interactions and appending new ones (§3.2).
 *
 * One *file*, not one cassette name — with a scope resolver in play a single name spans
 * several of these, one per scope, and they are independent of each other down to the lock
 * and the strict-mode check (§3.8). {@see CassetteSession} is what routes between them.
 *
 * All the state that outlives a single request lives here rather than on VcrClient: which
 * interactions have been played, and the file lock.
 */
final class CassetteManager
{
    private const LOCK_EXTENSION = 'cassette-lock';

    private bool $opened = false;

    private Cassette $cassette;

    private bool $recording = false;

    private bool $existed = false;

    private bool $holdsLock = false;

    /**
     * Whether VCR_ERASE_TAPE opened this cassette, kept apart from `recording` because a
     * `@provider` selector overrides the declared mode only for that API (§7 decision 76).
     */
    private bool $erasing = false;

    private EraseTape $eraseTape;

    private bool $eraseTapeHadNoEffect = false;

    private ?string $recordingBlocked = null;

    /** @var array<int, int> */
    private array $played = [];

    /**
     * How many interactions the cassette held when the session opened — the ones the
     * strict modes judge. Everything from this position on was recorded by this session.
     */
    private int $baseline = 0;

    /** @var list<int> the positions of the interactions replayed so far, in replay order */
    private array $sequence = [];

    private bool $verified = false;

    /** @var list<Interaction> */
    private array $recordedHere = [];

    private bool $warned = false;

    private bool $reportedLock = false;

    private bool $reportedUnplayed = false;

    public function __construct(
        private readonly string $name,
        private readonly ?string $scope,
        private readonly CassettePersisterInterface $persister,
        private readonly CassetteSerializerInterface $serializer,
        private readonly CompositeMatcher $matcher,
        private readonly ClockInterface $clock,
        private readonly Environment $environment,
        private readonly RecordMode $mode = RecordMode::RecordIfAbsent,
        private readonly StrictMode $strictMode = StrictMode::None,
        private readonly ?DateInterval $staleAfter = null,
        private readonly bool $repeatablePlayback = false,
        private readonly bool $locked = false,
        private readonly int $inlineBodyLimit = 1_048_576,
        public readonly HookRegistry $hooks = new HookRegistry,
        public readonly RedactionHooks $redaction = new RedactionHooks,
        private readonly ?SecretScanner $scanner = null,
        private readonly ?Closure $warn = null,
        private readonly bool $reportUnplayed = true,
    ) {
        $this->cassette = new Cassette;
    }

    /**
     * The cassette name as the test declared it, without any scope — what VCR_ERASE_TAPE
     * matches on and what an error message calls the cassette (§3.1).
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The scope this file was opened for, null when the cassette isn't scoped (§3.8).
     */
    public function scope(): ?string
    {
        return $this->scope;
    }

    public function mode(): RecordMode
    {
        return $this->mode;
    }

    /**
     * The scopes this cassette name already has files for, in alphabetical order.
     *
     * The one piece of information worth having when a scope turns up missing: "2024-01 is
     * on disk, the code is asking for 2024-04" is the whole diagnosis (§3.8).
     *
     * @return list<string>
     */
    public function existingScopes(): array
    {
        $prefix = $this->name.'.';
        $scopes = [];

        foreach ($this->persister->list($this->serializer->fileExtension(), $prefix) as $found) {
            $scopes[] = substr($found, strlen($prefix));
        }

        sort($scopes);

        return $scopes;
    }

    /**
     * The first interaction still available that matches, in recording order. Playing one
     * consumes it, so the same request twice replays two different recordings — unless the
     * interaction is repeatable, which is what retry logic in the code under test needs.
     */
    public function play(RecordedRequest $incoming): ?Interaction
    {
        $this->open();

        $incoming = $this->redaction->forMatching($incoming);

        foreach ($this->cassette->interactions as $position => $interaction) {
            if ($this->isSpent($position, $interaction)) {
                continue;
            }

            // Before the comparison, not after: a hook that puts a real value back into the
            // recorded request is the reason the recorded request can be compared with a
            // live one at all (§3.4).
            $interaction = $this->hooks->beforePlayback($interaction);

            if (! $this->matcher->matches($interaction->request, $incoming)) {
                continue;
            }

            $this->played[$position] = ($this->played[$position] ?? 0) + 1;

            if ($position < $this->baseline && ! $this->isRepeatable($interaction)) {
                $this->sequence[] = $position;
            }

            return $interaction;
        }

        return null;
    }

    /**
     * Why each interaction still available was turned down, keyed by its position in the
     * cassette, counting from 1.
     *
     * @return array<int, Mismatch>
     */
    public function mismatches(RecordedRequest $incoming): array
    {
        $this->open();

        $incoming = $this->redaction->forMatching($incoming);

        $mismatches = [];

        foreach ($this->cassette->interactions as $position => $interaction) {
            if ($this->isSpent($position, $interaction)) {
                continue;
            }

            $mismatch = $this->matcher->explainMismatch(
                $this->hooks->beforePlayback($interaction)->request,
                $incoming,
            );

            if ($mismatch !== null) {
                $mismatches[$position + 1] = $mismatch;
            }
        }

        return $mismatches;
    }

    /**
     * @return Interaction|null null when a beforeRecord hook refused the interaction, in
     *                          which case nothing was written
     */
    public function record(RecordedRequest $request, RecordedResponse $response): ?Interaction
    {
        return $this->append(Interaction::recorded($request, $response, $this->clock->now()));
    }

    /**
     * Persists a transport failure in place of a response — only ever reached with
     * recordTransportErrors on, since a transient network blip has no business becoming a
     * permanent part of a regression test.
     */
    public function recordFailure(RecordedRequest $request, RecordedError $error): ?Interaction
    {
        return $this->append(Interaction::failed($request, $error, $this->clock->now()));
    }

    private function append(Interaction $interaction): ?Interaction
    {
        $this->open();

        $interaction = $this->hooks->beforeRecord($interaction);

        if ($interaction === null) {
            return null;
        }

        // Read-modify-write, not a bare write: the appended interaction goes onto whatever
        // is on disk now, which is why the lock is taken before the read and not around
        // the write.
        $this->cassette = $this->readFromDisk()->withInteraction($interaction);
        $this->persist();

        $this->recordedHere[] = $interaction;

        // What this session recorded is not something this session then replays: a retry
        // loop under recording has to reach the real API each time round, or the cassette
        // would hold one response where the code asked for several.
        $this->played[count($this->cassette->interactions) - 1] = 1;

        return $interaction;
    }

    /**
     * Whether this session may perform and record a real request to $host: the mode allows
     * it, or forced recording is in play for that host's traffic.
     *
     * The host matters because a `@provider` selector overrides the declared mode only for
     * the API it named (§7 decision 76) — everything else in the same cassette follows the
     * mode the session was opened with, which is why the two are tracked apart. A host that
     * could not be read out of the request is no API a selector can have narrowed to, the
     * same reading {@see EraseTape::spares()} takes of an interaction.
     */
    public function isRecording(?string $host = null): bool
    {
        $this->open();

        if ($this->recording) {
            return true;
        }

        return $this->erasing && $this->eraseTape->erases($this->name, $host);
    }

    /**
     * Why the recording this session's mode called for isn't happening — null when nothing
     * is being blocked, either because recording is permitted or because the mode wasn't
     * going to record anyway.
     */
    public function recordingBlockedBecause(): ?string
    {
        $this->open();

        return $this->recordingBlocked;
    }

    public function cassetteExists(): bool
    {
        $this->open();

        return $this->existed;
    }

    public function interactionCount(): int
    {
        $this->open();

        return count($this->cassette->interactions);
    }

    /**
     * True when VCR_ERASE_TAPE named this cassette and every interaction in it was locked,
     * so the run erased nothing and requested nothing. Not an error — the lock doing its
     * one job — but said out loud when the session ends, so it doesn't look like the
     * variable was silently ignored.
     */
    public function eraseTapeHadNoEffect(): bool
    {
        $this->open();

        return $this->eraseTapeHadNoEffect;
    }

    public function location(): string
    {
        return $this->persister->describe($this->key());
    }

    /**
     * Ends the session: gives back what it holds, and only then checks what the strict
     * mode promised about the replay (§3.6). In that order, so a failed assertion never
     * leaves a lock behind for a parallel process to queue on.
     */
    public function close(): void
    {
        $this->prepare();
        $this->release();
        $this->verify();
    }

    /**
     * Reads the file if nothing else has, so that a strict check about to run has something
     * to judge — a session that made no request at all still promised something about what
     * is on disk. Before the lock goes back, since opening may need it.
     */
    public function prepare(): void
    {
        if ($this->strictMode !== StrictMode::None) {
            $this->open();
        }
    }

    /**
     * The half of close() that only gives things back — the lock, and the scan's findings.
     *
     * Split out for the destructor, which runs at a moment nothing chose: often while
     * another exception is already unwinding, and sometimes during shutdown, where PHP
     * turns a thrown exception into a fatal error with no usable stack. Releasing a lock
     * there is right; raising a failed assertion is not.
     */
    public function release(): void
    {
        $this->releaseLock();
        $this->reportLockedCassette();
        $this->reportSecrets();
        $this->reportUnplayed();
    }

    /**
     * @throws StrictModeViolationException
     */
    public function verify(): void
    {
        if ($this->strictMode === StrictMode::None || $this->verified) {
            return;
        }

        $this->verified = true;

        if ($this->strictMode === StrictMode::AllPlayed) {
            $this->verifyAllPlayed();

            return;
        }

        $this->verifyInOrder();
    }

    private function verifyAllPlayed(): void
    {
        $unplayed = [];

        foreach ($this->baseline() as $position => $interaction) {
            if (($this->played[$position] ?? 0) === 0) {
                $unplayed[$position] = $interaction;
            }
        }

        if ($unplayed !== []) {
            throw StrictModeViolationException::unplayed($this->location(), $unplayed, $this->baseline);
        }
    }

    /**
     * Replay picks the first interaction still available that matches, so replaying in
     * recording order means the positions come out ascending. A descent is the violation,
     * and the pair around it is what the message has to name.
     */
    private function verifyInOrder(): void
    {
        $baseline = $this->baseline();
        $previous = null;

        foreach ($this->sequence as $position) {
            if ($previous !== null && $position < $previous) {
                throw StrictModeViolationException::outOfOrder(
                    $this->location(),
                    $position,
                    $baseline[$position],
                    $previous,
                    $baseline[$previous],
                );
            }

            $previous = $position;
        }
    }

    /**
     * The interactions the cassette held when the session opened, keyed by position.
     *
     * @return array<int, Interaction>
     */
    private function baseline(): array
    {
        return array_slice($this->cassette->interactions, 0, $this->baseline, true);
    }

    /**
     * Says that forced recording came to nothing, which is the lock doing its job rather
     * than the variable being ignored — the difference between the two is invisible from
     * the outside, since both leave the file exactly as it was.
     */
    private function reportLockedCassette(): void
    {
        if (! $this->eraseTapeHadNoEffect || $this->reportedLock) {
            return;
        }

        $this->reportedLock = true;

        $this->report(sprintf(
            "%s %s\n  cassette fully locked, %s had no effect.\n",
            Ansi::yellow('http-vcr:'),
            $this->location(),
            Ansi::bold('VCR_ERASE_TAPE'),
        ));
    }

    /**
     * Says which recorded interactions the run never asked for, without failing anything.
     *
     * Only what the cassette already held when the session opened, and only if the session
     * actually opened it: interactions this run recorded itself have been replayed by
     * nobody by definition, and a test that never touched its client has not drifted from
     * anything. `StrictMode::AllPlayed` fails on the same finding, so it does not also get
     * warned about; `InOrder` says nothing about it, so it does.
     */
    private function reportUnplayed(): void
    {
        if (! $this->reportUnplayed || $this->reportedUnplayed || ! $this->opened) {
            return;
        }

        if ($this->strictMode === StrictMode::AllPlayed) {
            return;
        }

        $this->reportedUnplayed = true;
        $unplayed = [];

        foreach ($this->baseline() as $position => $interaction) {
            if (($this->played[$position] ?? 0) === 0) {
                $unplayed[$position] = $interaction;
            }
        }

        if ($unplayed === []) {
            return;
        }

        $lines = '';

        foreach ($unplayed as $position => $interaction) {
            $lines .= sprintf(
                "    #%d  %s %s\n",
                $position + 1,
                $interaction->request->method,
                $interaction->request->uri,
            );
        }

        $this->report(sprintf(
            "%s %s\n  %d of %d recorded interaction%s %s never replayed:\n%s",
            Ansi::yellow('http-vcr:'),
            $this->location(),
            count($unplayed),
            $this->baseline,
            $this->baseline === 1 ? '' : 's',
            count($unplayed) === 1 ? 'was' : 'were',
            $lines,
        ));
    }

    /**
     * Runs what this session recorded past the secret heuristic and says what it found.
     *
     * Only what this session recorded: a cassette re-read every run would repeat warnings
     * about content already looked at and knowingly accepted, and after a fortnight nobody
     * reads those. It never fails anything either — the cassette is already written, and
     * the point is to put the finding in front of someone while the context is fresh.
     */
    private function reportSecrets(): void
    {
        if ($this->scanner === null || $this->warned || $this->recordedHere === []) {
            return;
        }

        $this->warned = true;
        $findings = [];

        foreach ($this->recordedHere as $interaction) {
            $findings = array_merge($findings, $this->scanner->scan($interaction));
        }

        if ($findings === []) {
            return;
        }

        $this->report(SecretScanner::warning($this->location(), count($this->recordedHere), $findings));
    }

    /**
     * Standard error unless a harness offered somewhere better: a test runner collects the
     * whole run's warnings and prints them together, where they can still be read after
     * hundreds of tests have scrolled past (§3.4).
     *
     * The collector is looked up rather than waited for (§7 decision 75). A session told
     * where to report still reports there — `warn:` is how a caller overrides the ambient
     * choice, including back to standard error with `fn ($w) => fwrite(STDERR, $w)`.
     */
    private function report(string $warning): void
    {
        if ($this->warn !== null) {
            ($this->warn)($warning);

            return;
        }

        $collector = RunWarnings::current();

        if ($collector !== null) {
            $collector->report($warning);

            return;
        }

        file_put_contents('php://stderr', $warning);
    }

    private function open(): void
    {
        if ($this->opened) {
            return;
        }

        $this->opened = true;
        $this->existed = $this->persister->exists($this->key());

        $this->openCassette();

        // Read after the session has settled on its contents — under forced recording that
        // means after the truncation, which is part of opening rather than something that
        // happens once the session is under way (§3.6).
        $this->baseline = count($this->cassette->interactions);

        $this->refuseStaleCassette();
    }

    /**
     * Crossing `staleAfter` is a report, not a failure — unless this run asked for it to be
     * one (§3.7). Checked when the session opens, so a run that is going to be stopped is
     * stopped before the code under test has half-finished on replayed data.
     */
    private function refuseStaleCassette(): void
    {
        if ($this->staleAfter === null || ! $this->environment->enforcesStaleCheck()) {
            return;
        }

        $staleness = new Staleness($this->clock);
        $stale = $staleness->in($this->cassette, $this->staleAfter);

        if ($stale !== []) {
            throw StaleCassetteException::past(
                $this->name,
                $this->location(),
                $stale,
                $this->staleAfter,
                $staleness,
            );
        }
    }

    private function openCassette(): void
    {
        $this->eraseTape = $eraseTape = $this->environment->eraseTape();

        if ($eraseTape->covers($this->name)) {
            $this->openErased();

            return;
        }

        if ($this->mode === RecordMode::ExtendCassette) {
            $this->openForExtending();

            return;
        }

        if ($this->mode === RecordMode::RecordIfAbsent && ! $this->existed) {
            $this->openForFirstRecording();

            return;
        }

        $this->cassette = $this->readFromDisk();
    }

    /**
     * The cassette keeps everything it holds and grows: recorded interactions replay as
     * usual, and whatever matches none of them is appended.
     *
     * The lock is taken when the session opens rather than when something is finally
     * appended, because an append is a read-modify-write of the file — the read that
     * precedes it has to be inside the same lock, or a parallel session's interaction is
     * overwritten by this one's copy of the file.
     */
    private function openForExtending(): void
    {
        if (! $this->environment->isRecordingAllowed()) {
            // Replaying what is already there is unaffected: only the branch that would
            // have appended something is blocked, and it says so when a request reaches it.
            $this->recordingBlocked = $this->environment->recordingBlockedBecause();
            $this->cassette = $this->readFromDisk();

            return;
        }

        $this->takeLock();

        $this->existed = $this->persister->exists($this->key());
        $this->recording = true;
        $this->cassette = $this->readFromDisk();
    }

    /**
     * Forced recording truncates the file when the session opens, down to what the
     * selector spares: locked interactions always, and everything outside the API a
     * `@provider` selector narrowed to. Requests are still matched against those
     * survivors — that is what makes leaving a locked interaction alone possible.
     *
     * The override of the declared mode is narrowed the same way (§7 decision 76), so
     * `recording` keeps meaning what it means everywhere else — whether the mode this
     * session declared would record — and `erasing` carries the tape's override beside it.
     * A request to an API the selector never named then follows the mode, which for
     * `PlaybackOnly` and for `RecordIfAbsent` on a cassette that was already there means
     * refusing rather than reaching the real API.
     */
    private function openErased(): void
    {
        if (! $this->environment->isRecordingAllowed()) {
            throw RecordingNotAllowedException::forErasedCassette(
                $this->name,
                (string) $this->environment->recordingBlockedBecause(),
            );
        }

        $this->takeLock();
        $this->erasing = true;
        $this->recording = $this->mode === RecordMode::ExtendCassette
            || ($this->mode === RecordMode::RecordIfAbsent && ! $this->existed);

        $recorded = $this->readFromDisk();
        $eraseTape = $this->environment->eraseTape();

        $survivors = array_values(array_filter(
            $recorded->interactions,
            fn (Interaction $interaction): bool => $this->locked || $eraseTape->spares($this->name, $interaction),
        ));

        $this->cassette = $recorded->withInteractions($survivors);

        // Narrowly the fully-locked case, not "nothing matched": a `@provider` selector is
        // meant to pass over the cassettes belonging to other APIs, and saying so on each
        // of them would be noise on the normal path.
        $this->eraseTapeHadNoEffect = $recorded->interactions !== [] && $this->allLocked($recorded->interactions);

        // Only when the truncation actually removed something. A `@provider` selector opens
        // every cassette the run touches, and rewriting the ones it took nothing out of
        // would restamp half the cassette directory on a run that changed none of it.
        if (! $this->eraseTapeHadNoEffect && $this->existed && count($survivors) !== count($recorded->interactions)) {
            $this->persist();
        }
    }

    /**
     * The "does the cassette exist?" question is answered under the lock, not before it:
     * two parallel processes starting the same not-yet-recorded test would otherwise both
     * see nothing and both record, the second appending a duplicate of what the first had
     * just written.
     */
    private function openForFirstRecording(): void
    {
        if (! $this->environment->isRecordingAllowed()) {
            // No lock, no directory to create: a run that isn't allowed to record must be
            // able to work off a cassette directory it can only read.
            $this->recordingBlocked = $this->environment->recordingBlockedBecause();

            return;
        }

        $this->takeLock();

        if ($this->persister->exists($this->key())) {
            $this->releaseLock();
            $this->existed = true;
            $this->cassette = $this->readFromDisk();

            return;
        }

        $this->recording = true;
        $this->cassette = new Cassette;
    }

    /**
     * @param  list<Interaction>  $interactions
     */
    private function allLocked(array $interactions): bool
    {
        if ($this->locked) {
            return true;
        }

        foreach ($interactions as $interaction) {
            if (! $interaction->locked) {
                return false;
            }
        }

        return true;
    }

    private function isSpent(int $position, Interaction $interaction): bool
    {
        if ($this->isRepeatable($interaction)) {
            return false;
        }

        return ($this->played[$position] ?? 0) > 0;
    }

    /**
     * A repeatable interaction is never consumed, and for the same reason it sits outside
     * the InOrder sequence: something replayable at any point in the run says nothing
     * about the order of the interactions around it.
     */
    private function isRepeatable(Interaction $interaction): bool
    {
        return $interaction->repeatablePlayback || $this->repeatablePlayback;
    }

    private function readFromDisk(): Cassette
    {
        $content = $this->persister->read($this->key());

        if ($content === null) {
            return new Cassette;
        }

        try {
            return $this->serializer->deserialize($content, $this->sidecars());
        } catch (CassetteFormatException $exception) {
            throw $exception->in($this->location());
        }
    }

    private function persist(): void
    {
        $sidecars = $this->sidecars();

        $this->persister->write($this->key(), $this->serializer->serialize($this->cassette, $sidecars));

        // Only now is the live set of references known: whatever this write didn't use is
        // a file nothing points at any more.
        $sidecars->collectGarbage();
    }

    /**
     * A fresh store per pass, so the references it saw are exactly the ones this cassette
     * currently holds.
     */
    private function sidecars(): SidecarBodies
    {
        return new SidecarBodies($this->persister, $this->fileName(), $this->inlineBodyLimit);
    }

    private function takeLock(): void
    {
        if ($this->persister instanceof SupportsSessionLocking && ! $this->holdsLock) {
            $this->persister->lock($this->lockKey());
            $this->holdsLock = true;
        }
    }

    private function releaseLock(): void
    {
        if ($this->persister instanceof SupportsSessionLocking && $this->holdsLock) {
            $this->persister->unlock($this->lockKey());
            $this->holdsLock = false;
        }
    }

    private function key(): string
    {
        return $this->fileName().'.'.$this->serializer->fileExtension();
    }

    private function lockKey(): string
    {
        return $this->fileName().'.'.self::LOCK_EXTENSION;
    }

    /**
     * The cassette name with the scope appended, without a format extension — the file this
     * session actually works on, and the namespace its sidecars belong to.
     */
    private function fileName(): string
    {
        return $this->scope === null ? $this->name : $this->name.'.'.$this->scope;
    }
}
