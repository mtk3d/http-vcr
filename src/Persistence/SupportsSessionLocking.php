<?php

declare(strict_types=1);

namespace HttpVcr\Persistence;

/**
 * A persister that can hold an exclusive lock for the length of a recording session, so
 * two parallel test processes can't interleave their writes into one cassette (§3.2).
 *
 * Separate from {@see CassettePersisterInterface} because it is not implementable
 * everywhere, and a store that can't do it is still a perfectly good store for replaying.
 */
interface SupportsSessionLocking
{
    /**
     * Blocks until the lock is available. The key is the lock's own, never the cassette's:
     * a cassette is replaced by an atomic rename, which swaps the file's inode, and a lock
     * held on an inode no longer at that path excludes nothing.
     */
    public function lock(string $key): void;

    public function unlock(string $key): void;
}
