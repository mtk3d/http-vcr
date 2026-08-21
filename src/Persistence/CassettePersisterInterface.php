<?php

declare(strict_types=1);

namespace HttpVcr\Persistence;

/**
 * Where cassettes live. A store of bytes keyed by name — it knows nothing about the
 * serialization format, so the caller passes keys with the extension already on them.
 */
interface CassettePersisterInterface
{
    public function read(string $key): ?string;

    public function write(string $key, string $content): void;

    public function delete(string $key): void;

    public function exists(string $key): bool;

    /**
     * Cassette names (without the extension) stored in the given format, under $prefix.
     *
     * The extension is an argument rather than something the persister decides, because a
     * store of bytes has no idea which of its entries are cassettes: sidecar bodies and
     * lock files go through this same channel, and a command listing cassettes must not
     * try to deserialize those.
     *
     * A persister that can't enumerate its contents returns nothing, so a caller can say
     * so out loud instead of quietly reporting an empty project.
     *
     * @return iterable<string>
     */
    public function list(string $extension, string $prefix = ''): iterable;

    /**
     * Where this key lives, in whatever terms make sense for the store — a file path here,
     * something else elsewhere. Diagnostics only: an error message about a cassette is
     * worth little without saying which file it means.
     */
    public function describe(string $key): string;
}
