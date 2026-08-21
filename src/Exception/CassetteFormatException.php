<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use RuntimeException;

/**
 * A cassette's schemaVersion is unknown to this installation, or its contents can't be
 * deserialized.
 *
 * @phpstan-consistent-constructor subclasses keep the constructor, so attributing a problem
 *                                to a file can rebuild whichever kind it was
 */
class CassetteFormatException extends RuntimeException implements VcrException
{
    public static function unsupportedSchemaVersion(int $found, int $supported): self
    {
        return new self(sprintf(
            'schema version %d, where this installation of http-vcr writes and reads %d. Upgrade http-vcr to read it.',
            $found,
            $supported,
        ));
    }

    public static function malformed(string $problem): self
    {
        return new self($problem);
    }

    /**
     * Names the file the problem is in. The serializer works on a string and has no idea
     * where it came from; whoever read that string does.
     *
     * Returns the same kind of exception it was called on, so attributing a problem to a
     * file doesn't turn an integrity failure into a generic format one.
     */
    public function in(string $cassetteLocation): static
    {
        return new static(sprintf('Cassette %s: %s', $cassetteLocation, $this->getMessage()), 0, $this);
    }
}
