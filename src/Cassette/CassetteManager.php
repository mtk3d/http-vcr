<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

use HttpVcr\Clock\ClockInterface;
use HttpVcr\Environment;
use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\Exception\RecordingNotAllowedException;
use HttpVcr\Matching\CompositeMatcher;
use HttpVcr\Matching\Mismatch;
use HttpVcr\Persistence\CassettePersisterInterface;
use HttpVcr\Persistence\SidecarBodies;
use HttpVcr\Persistence\SupportsSessionLocking;
use HttpVcr\RecordMode;
use HttpVcr\Serializer\CassetteSerializerInterface;

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

    private Cassette $cassette;

    private bool $recording = false;

    private bool $existed = false;

    private bool $holdsLock = false;

    private bool $eraseTapeHadNoEffect = false;

    private ?string $recordingBlocked = null;

    /** @var array<int, int> */
    private array $played = [];

    public function __construct(
        private readonly string $name,
        private readonly CassettePersisterInterface $persister,
        private readonly CassetteSerializerInterface $serializer,
        private readonly CompositeMatcher $matcher,
        private readonly ClockInterface $clock,
        private readonly Environment $environment,
        private readonly RecordMode $mode = RecordMode::RecordIfAbsent,
        private readonly bool $repeatablePlayback = false,
        private readonly bool $locked = false,
        private readonly int $inlineBodyLimit = 1_048_576,
    ) {
        $this->cassette = new Cassette();
    }

    /**
     * The first interaction still available that matches, in recording order. Playing one
     * consumes it, so the same request twice replays two different recordings — unless the
     * interaction is repeatable, which is what retry logic in the code under test needs.
     */
    public function play(RecordedRequest $incoming): ?Interaction
    {
        $this->open();

        foreach ($this->cassette->interactions as $position => $interaction) {
            if ($this->isSpent($position, $interaction) || !$this->matcher->matches($interaction->request, $incoming)) {
                continue;
            }

            $this->played[$position] = ($this->played[$position] ?? 0) + 1;

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

        $mismatches = [];

        foreach ($this->cassette->interactions as $position => $interaction) {
            if ($this->isSpent($position, $interaction)) {
                continue;
            }

            $mismatch = $this->matcher->explainMismatch($interaction->request, $incoming);

            if ($mismatch !== null) {
                $mismatches[$position + 1] = $mismatch;
            }
        }

        return $mismatches;
    }

    public function record(RecordedRequest $request, RecordedResponse $response): Interaction
    {
        return $this->append(Interaction::recorded($request, $response, $this->clock->now()));
    }

    /**
     * Persists a transport failure in place of a response — only ever reached with
     * recordTransportErrors on, since a transient network blip has no business becoming a
     * permanent part of a regression test.
     */
    public function recordFailure(RecordedRequest $request, RecordedError $error): Interaction
    {
        return $this->append(Interaction::failed($request, $error, $this->clock->now()));
    }

    private function append(Interaction $interaction): Interaction
    {
        $this->open();

        // Read-modify-write, not a bare write: the appended interaction goes onto whatever
        // is on disk now, which is why the lock is taken before the read and not around
        // the write.
        $this->cassette = $this->readFromDisk()->withInteraction($interaction);
        $this->persist();

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

    public function close(): void
    {
        $this->releaseLock();
    }

    private function open(): void
    {
        if ($this->opened) {
            return;
        }

        $this->opened = true;
        $this->existed = $this->persister->exists($this->key());

        $eraseTape = $this->environment->eraseTape();

        if ($eraseTape->covers($this->name)) {
            $this->openErased();

            return;
        }

        if ($this->mode === RecordMode::RecordIfAbsent && !$this->existed) {
            $this->openForFirstRecording();

            return;
        }

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
        if ($interaction->repeatablePlayback || $this->repeatablePlayback) {
            return false;
        }

        return ($this->played[$position] ?? 0) > 0;
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
