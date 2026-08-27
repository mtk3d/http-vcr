<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Config;
use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\Persistence\CassettePersisterInterface;
use HttpVcr\Persistence\FilesystemCassettePersister;
use HttpVcr\Persistence\SidecarBodies;
use HttpVcr\Persistence\SupportsSessionLocking;
use HttpVcr\Serializer\CassetteSerializerInterface;

/**
 * Reading and writing a cassette file from outside any session, for the commands that edit
 * one in place.
 *
 * Separate from {@see CassetteManager} because it has none of a session's
 * concerns — no matching, no consumption, no recording permission. What it does share is
 * the file lock: a command rewriting a cassette while a test run appends to it would
 * silently drop whichever write landed second.
 */
final class CassetteEditor
{
    private const LOCK_EXTENSION = 'cassette-lock';

    private readonly CassettePersisterInterface $persister;

    private readonly CassetteSerializerInterface $serializer;

    private readonly int $inlineBodyLimit;

    /**
     * @param  string|null  $directory  a cassette directory of this class's own — what
     *                                  `#[CassetteDirectory]` declares (§3.12); null leaves it
     *                                  to the project configuration
     * @param  CassetteSerializerInterface|null  $serializer  a format other than the configured
     *                                                        one, for `migrate`, which has to
     *                                                        read one and write the other
     */
    public function __construct(Config $config, ?string $directory = null, ?CassetteSerializerInterface $serializer = null)
    {
        $this->persister = $directory === null ? $config->persister() : new FilesystemCassettePersister($directory);
        $this->serializer = $serializer ?? $config->serializer();
        $this->inlineBodyLimit = $config->inlineBodyLimit();
    }

    /**
     * The files a cassette name has on disk: its own, plus one per scope (§3.8). A scope
     * narrows that to the single file — and yields nothing at all if that file isn't there,
     * which the caller reports rather than silently doing nothing.
     *
     * @return list<string> file names — the cassette name with the scope appended, without
     *                      the format extension
     */
    public function files(string $name, ?string $scope): array
    {
        if ($scope !== null) {
            $scoped = $name.'.'.$scope;

            return $this->persister->exists($this->key($scoped)) ? [$scoped] : [];
        }

        $files = [];

        foreach ($this->persister->list($this->serializer->fileExtension(), $name) as $found) {
            // The prefix alone would also catch a neighbour whose name merely starts the
            // same way: `shopify/checkout-retry` is not a scope of `shopify/checkout`.
            if ($found === $name || str_starts_with($found, $name.'.')) {
                $files[] = $found;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every cassette in the directory, scope files included — they are separate recordings
     * with separate contents, so nothing is collapsed onto a base name here.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $files = [];

        foreach ($this->persister->list($this->serializer->fileExtension()) as $file) {
            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    public function describe(string $file): string
    {
        return $this->persister->describe($this->key($file));
    }

    public function exists(string $file): bool
    {
        return $this->persister->exists($this->key($file));
    }

    /**
     * The cassette file itself. Sidecar bodies are left where they are: they are named
     * after the cassette without its format extension, so they belong just as much to
     * whatever wrote the file that replaces this one.
     */
    public function delete(string $file): void
    {
        $this->persister->delete($this->key($file));
    }

    /**
     * @throws CassetteFormatException
     */
    public function read(string $file): Cassette
    {
        $content = (string) $this->persister->read($this->key($file));

        try {
            return $this->serializer->deserialize($content, $this->sidecars($file));
        } catch (CassetteFormatException $exception) {
            throw $exception->in($this->describe($file));
        }
    }

    public function write(string $file, Cassette $cassette): void
    {
        $sidecars = $this->sidecars($file);

        $this->persister->write($this->key($file), $this->serializer->serialize($cassette, $sidecars));
        $sidecars->collectGarbage();
    }

    /**
     * Holds the session lock for the whole read-modify-write, so an edit and a recording
     * run cannot each write a copy of the file the other never saw.
     *
     * @template T
     *
     * @param  callable(): T  $edit
     * @return T
     */
    public function locking(string $file, callable $edit): mixed
    {
        if (! $this->persister instanceof SupportsSessionLocking) {
            return $edit();
        }

        $key = $file.'.'.self::LOCK_EXTENSION;
        $this->persister->lock($key);

        try {
            return $edit();
        } finally {
            $this->persister->unlock($key);
        }
    }

    private function sidecars(string $file): SidecarBodies
    {
        return new SidecarBodies($this->persister, $file, $this->inlineBodyLimit);
    }

    private function key(string $file): string
    {
        return $file.'.'.$this->serializer->fileExtension();
    }
}
