<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

/**
 * The contents of one cassette file: a schema version and the interactions, in recording
 * order. Pure data — reading and writing it is {@see CassetteManager}'s job.
 */
final readonly class Cassette
{
    /**
     * The version this build of http-vcr writes. Older, still-supported versions are
     * upgraded on read; a newer one throws rather than guessing at an unknown shape.
     */
    public const CURRENT_SCHEMA_VERSION = 1;

    /**
     * @param  list<Interaction>  $interactions
     */
    public function __construct(
        public array $interactions = [],
        public int $schemaVersion = self::CURRENT_SCHEMA_VERSION,
    ) {}

    public function withInteraction(Interaction $interaction): self
    {
        return new self([...$this->interactions, $interaction], $this->schemaVersion);
    }

    /**
     * @param  list<Interaction>  $interactions
     */
    public function withInteractions(array $interactions): self
    {
        return new self($interactions, $this->schemaVersion);
    }

    public function isEmpty(): bool
    {
        return $this->interactions === [];
    }
}
