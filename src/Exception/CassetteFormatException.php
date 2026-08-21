<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use RuntimeException;

/**
 * A cassette's schemaVersion is unknown to this installation, or its contents can't be
 * deserialized.
 */
class CassetteFormatException extends RuntimeException implements VcrException
{
    public static function unsupportedSchemaVersion(string $cassetteLocation, int $found, int $supported): self
    {
        return new self(sprintf(
            'Cassette %s is written in schema version %d; this version of http-vcr understands %d. '
            . 'Upgrade http-vcr to read it.',
            $cassetteLocation,
            $found,
            $supported,
        ));
    }

    public static function malformed(string $cassetteLocation, string $problem): self
    {
        return new self(sprintf('Cassette %s could not be read: %s.', $cassetteLocation, $problem));
    }
}
