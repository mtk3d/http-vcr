<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Support;

use Closure;
use HttpVcr\Persistence\CassettePersisterInterface;
use HttpVcr\Persistence\SupportsSessionLocking;

/**
 * A persister that keeps everything in memory and records the locking it was asked for,
 * so the session rules can be tested without a second process.
 */
final class InMemoryCassettePersister implements CassettePersisterInterface, SupportsSessionLocking
{
    /** @var array<string, string> */
    private array $entries = [];

    /** @var list<string> */
    public array $locked = [];

    /** @var list<string> */
    public array $unlocked = [];

    /** @var list<string> */
    public array $writes = [];

    private ?Closure $onLock = null;

    /**
     * Simulates another process getting there first: whatever this does happens while the
     * lock is being taken, before the manager re-checks.
     */
    public function whileLocking(Closure $callback): void
    {
        $this->onLock = $callback;
    }

    public function read(string $key): ?string
    {
        return $this->entries[$key] ?? null;
    }

    public function write(string $key, string $content): void
    {
        $this->entries[$key] = $content;
        $this->writes[] = $key;
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function exists(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    public function list(string $extension, string $prefix = ''): iterable
    {
        foreach (array_keys($this->entries) as $key) {
            if (str_ends_with($key, '.'.$extension) && str_starts_with($key, $prefix)) {
                yield substr($key, 0, -strlen($extension) - 1);
            }
        }
    }

    public function describe(string $key): string
    {
        return 'memory:'.$key;
    }

    public function lock(string $key): void
    {
        $this->locked[] = $key;

        if ($this->onLock !== null) {
            ($this->onLock)($this);
            $this->onLock = null;
        }
    }

    public function unlock(string $key): void
    {
        $this->unlocked[] = $key;
    }
}
