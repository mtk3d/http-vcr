<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

use DateInterval;
use DateTimeImmutable;
use HttpVcr\Clock\FrozenClock;
use HttpVcr\RecordMode;
use Psr\Clock\ClockInterface;

/**
 * Which interactions in a cassette have outlived their `staleAfter` threshold (§3.7).
 *
 * Per interaction rather than per file: in {@see RecordMode::ExtendCassette} a
 * cassette grows over time, so one call recorded months ago would otherwise condemn a file
 * whose other twenty interactions are a week old.
 *
 * "Now" comes from a PSR-20 clock, so a test — this library's own, or a consumer's testing
 * its own thresholds with the {@see FrozenClock} that ships here — can sit
 * on either side of the threshold without waiting out real time.
 */
final readonly class Staleness
{
    public function __construct(private ClockInterface $clock) {}

    /**
     * @return array<int, Interaction> the stale interactions, keyed by their position in
     *                                 the cassette, counting from 0
     */
    public function in(Cassette $cassette, DateInterval $staleAfter): array
    {
        $now = $this->clock->now();
        $stale = [];

        foreach ($cassette->interactions as $position => $interaction) {
            if ($this->expiryOf($interaction, $staleAfter) < $now) {
                $stale[$position] = $interaction;
            }
        }

        return $stale;
    }

    public function expiryOf(Interaction $interaction, DateInterval $staleAfter): DateTimeImmutable
    {
        return $interaction->recordedAt->add($staleAfter);
    }
}
