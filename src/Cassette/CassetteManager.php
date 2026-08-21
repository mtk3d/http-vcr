<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

use Closure;
use DateInterval;
use HttpVcr\Environment;
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
use HttpVcr\SecretScanner;
use HttpVcr\Serializer\CassetteSerializerInterface;
use HttpVcr\StrictMode;
use Psr\Clock\ClockInterface;

/**
 * One cassette session: opening the file, deciding whether this run may record into it,
 * handing out matching interactions and appending new ones (§3.2).
 *
 * All the state that outlives a single request lives here rather than on VcrClient —
 * which interactions have been played, and the file lock.
 */
final class CassetteManager
{
    private const LOCK_EXTENSION = 'cassette-lock';

    private bool $opened = false;

    private bool $started = false;

    private Cassette $cassette;

    private bool $recording = false;

    private bool $existed = false;

    private bool $holdsLock = false;

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

    public function __construct(
        private readonly string $name,
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
        public readonly HookRegistry $hooks = new HookRegistry(),
        public readonly RedactionHooks $redaction = new RedactionHooks(),
        private readonly ?SecretScanner $scanner = null,
        private readonly ?Closure $warn = null,
    ) {
        $this->cassette = new Cassette();

        // Registered before the session exists, so redaction is always the first hook in
        // either direction: a project-wide rule runs ahead of anything added by hand.
        $this->hooks->addBeforeRecord($this->redaction->beforeRecord(...));
        $this->hooks->addBeforePlayback($this->redaction->beforePlayback(...));
    }

    /**
     * Marks the session as under way: from here on it is too late to register a hook or
     * anything else that would have changed an interaction already on its way past.
     *
     * On the session rather than on VcrClient, because the Guzzle bridge builds a fresh
     * client per request out of withInner() and a flag on the instance would never see a
     * second request (§3.14).
     */
    public function begin(): void
    {
        $this->started = true;
    }

    public function hasStarted(): bool
    {
        return $this->started;
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

            if (!$this->matcher->matches($interaction->request, $incoming)) {
                continue;
            }

            $this->played[$position] = ($this->played[$position] ?? 0) + 1;

            if ($position < $this->baseline && !$this->isRepeatable($interaction)) {
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
     * Whether this session may perform and record real requests: the mode allows it, or
     * forced recording is in play for this cassette.
     */
    public function isRecording(): bool
    {
        $this->open();

        return $this->recording;
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
     * one job — but worth saying out loud, so it doesn't look like the variable was
     * silently ignored.
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
        if ($this->strictMode !== StrictMode::None) {
            // A session that made no request at all has still promised something about the
            // cassette on disk, so there is something to read before the check can mean
            // anything — and it has to happen before the lock goes back.
            $this->open();
        }

        $this->release();
        $this->verifyStrictMode();
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
        $this->reportSecrets();
    }

    /**
     * @throws StrictModeViolationException
     */
    private function verifyStrictMode(): void
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

        $warning = SecretScanner::warning($this->location(), count($this->recordedHere), $findings);

        if ($this->warn !== null) {
            ($this->warn)($warning);

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
        if ($this->staleAfter === null || !$this->environment->enforcesStaleCheck()) {
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
        $eraseTape = $this->environment->eraseTape();

        if ($eraseTape->covers($this->name)) {
            $this->openErased();

            return;
        }

        if ($this->mode === RecordMode::ExtendCassette) {
            $this->openForExtending();

            return;
        }

        if ($this->mode === RecordMode::RecordIfAbsent && !$this->existed) {
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
        if (!$this->environment->isRecordingAllowed()) {
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
     */
    private function openErased(): void
    {
        if (!$this->environment->isRecordingAllowed()) {
            throw RecordingNotAllowedException::forErasedCassette(
                $this->name,
                (string) $this->environment->recordingBlockedBecause(),
            );
        }

        $this->takeLock();
        $this->recording = true;

        $recorded = $this->readFromDisk();
        $eraseTape = $this->environment->eraseTape();

        $survivors = array_values(array_filter(
            $recorded->interactions,
            fn (Interaction $interaction): bool => $this->locked || $eraseTape->spares($this->name, $interaction),
        ));

        $this->cassette = $recorded->withInteractions($survivors);
        $this->eraseTapeHadNoEffect = $recorded->interactions !== [] && count($survivors) === count($recorded->interactions);

        if (!$this->eraseTapeHadNoEffect && $this->existed) {
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
        if (!$this->environment->isRecordingAllowed()) {
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
        $this->cassette = new Cassette();
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
            return new Cassette();
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
        return new SidecarBodies($this->persister, $this->name, $this->inlineBodyLimit);
    }

    private function takeLock(): void
    {
        if ($this->persister instanceof SupportsSessionLocking && !$this->holdsLock) {
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
        return $this->name . '.' . $this->serializer->fileExtension();
    }

    private function lockKey(): string
    {
        return $this->name . '.' . self::LOCK_EXTENSION;
    }
}
