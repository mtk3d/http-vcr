<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use DateInterval;
use DateTimeInterface;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\Staleness;
use RuntimeException;

/**
 * A cassette has interactions older than its `staleAfter` threshold, and this run was
 * asked to treat that as an error (§3.7).
 *
 * Only ever reached under `VCR_ENFORCE_STALE_CHECK`: on its own, crossing the threshold is
 * a report, not a failure, because a check against the clock gives two different answers
 * for the same commit depending on when the pipeline happened to run.
 */
final class StaleCassetteException extends RuntimeException implements VcrException
{
    /**
     * @param  array<int, Interaction>  $stale  keyed by position in the cassette, from 0
     */
    public static function past(
        string $cassetteName,
        string $cassetteLocation,
        array $stale,
        DateInterval $staleAfter,
        Staleness $staleness,
    ): self {
        $lines = [];

        foreach ($stale as $position => $interaction) {
            $lines[] = sprintf(
                '  #%d  %s %s  recorded %s, stale since %s',
                $position + 1,
                $interaction->request->method,
                $interaction->request->uri,
                $interaction->recordedAt->format(DateTimeInterface::ATOM),
                $staleness->expiryOf($interaction, $staleAfter)->format(DateTimeInterface::ATOM),
            );
        }

        return new self(sprintf(
            '%d interaction%s in %s %s past the staleAfter threshold, and VCR_ENFORCE_STALE_CHECK '
            ."makes that an error:\n%s\n"
            .'Re-record with VCR_ERASE_TAPE=%s, or let this one run through with '
            .'VCR_IGNORE_STALE_CASSETTES=1.',
            count($lines),
            count($lines) === 1 ? '' : 's',
            $cassetteLocation,
            count($lines) === 1 ? 'is' : 'are',
            implode("\n", $lines),
            $cassetteName,
        ));
    }
}
