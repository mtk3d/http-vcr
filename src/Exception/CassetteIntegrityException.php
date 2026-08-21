<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

/**
 * A body kept outside the cassette file no longer matches the checksum recorded for it —
 * hand-edited, truncated, or partially restored from a backup.
 *
 * A specialization of {@see CassetteFormatException}: the cassette can be read, but what it
 * points at can't be trusted, and handing back the wrong bytes silently would be worse than
 * failing.
 */
final class CassetteIntegrityException extends CassetteFormatException
{
    public static function missingBodyFile(string $location): self
    {
        return new self(sprintf(
            'references the body file %s, which is not there.',
            $location,
        ));
    }

    public static function alteredBodyFile(string $location): self
    {
        return new self(sprintf(
            'has a body file %s that no longer matches its recorded bodySha256.',
            $location,
        ));
    }
}
