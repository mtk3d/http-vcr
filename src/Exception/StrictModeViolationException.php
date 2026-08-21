<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use HttpVcr\Cassette\Interaction;
use RuntimeException;

/**
 * The cassette closed without satisfying the {@see \HttpVcr\StrictMode} it was opened
 * with (§3.6).
 *
 * Not a matching failure: every request the code made was answered. What failed is the
 * assertion about the recording as a whole — something in it was never asked for, or was
 * asked for out of turn.
 */
final class StrictModeViolationException extends RuntimeException implements VcrException
{
    /**
     * @param array<int, Interaction> $unplayed keyed by position in the cassette, from 0
     */
    public static function unplayed(string $cassetteLocation, array $unplayed, int $total): self
    {
        $lines = [];

        foreach ($unplayed as $position => $interaction) {
            $lines[] = sprintf(
                '  #%d  %s %s',
                $position + 1,
                $interaction->request->method,
                $interaction->request->uri,
            );
        }

        return new self(sprintf(
            "StrictMode::AllPlayed: %d of %d recorded interaction%s in %s %s never replayed:\n%s",
            count($lines),
            $total,
            $total === 1 ? '' : 's',
            $cassetteLocation,
            count($lines) === 1 ? 'was' : 'were',
            implode("\n", $lines),
        ));
    }

    public static function outOfOrder(
        string $cassetteLocation,
        int $position,
        Interaction $interaction,
        int $afterPosition,
        Interaction $after,
    ): self {
        return new self(sprintf(
            "StrictMode::InOrder: %s was replayed out of the order it was recorded in.\n"
            . "  #%d  %s %s  came after\n"
            . '  #%d  %s %s',
            $cassetteLocation,
            $position + 1,
            $interaction->request->method,
            $interaction->request->uri,
            $afterPosition + 1,
            $after->request->method,
            $after->request->uri,
        ));
    }
}
