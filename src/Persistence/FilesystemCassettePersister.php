<?php

declare(strict_types=1);

namespace HttpVcr\Persistence;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * One file per cassette, under a single directory (§3.2).
 *
 * Writes are atomic: content goes to a temporary file in the same directory and is moved
 * into place with rename(), which is atomic within one filesystem. That is also what lets
 * a replaying session take no lock at all — a reader sees either the whole old file or the
 * whole new one, never a half-written mix, so an ordinary CI run needs no write access
 * here.
 */
final class FilesystemCassettePersister implements CassettePersisterInterface, SupportsSessionLocking
{
    /**
     * Lock files live in one hidden directory inside the cassette directory, rather than
     * beside the cassettes themselves. They belong on the same filesystem as the resource
     * they guard — the system temp directory isn't shared across a container boundary,
     * where the cassette directory routinely is — but they are the library's business, not
     * something to leaf through while reading recordings. The directory carries its own
     * `.gitignore`, so a project needs no setup to keep it out of version control.
     */
    private const INTERNAL_DIRECTORY = '.http-vcr';

    /** @var array<string, resource> */
    private array $locks = [];

    public function __construct(private readonly string $directory) {}

    public function read(string $key): ?string
    {
        $path = $this->path($key);

        if (! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read %s.', $path));
        }

        return $content;
    }

    public function write(string $key, string $content): void
    {
        $path = $this->path($key);
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory %s.', $directory));
        }

        $temporary = tempnam($directory, '.http-vcr-');

        if ($temporary === false) {
            throw new RuntimeException(sprintf('Could not create a temporary file in %s.', $directory));
        }

        if (file_put_contents($temporary, $content) === false || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(sprintf('Could not write %s.', $path));
        }

        chmod($path, 0o644);
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function list(string $extension, string $prefix = ''): iterable
    {
        if (! is_dir($this->directory)) {
            return;
        }

        $suffix = '.'.$extension;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->directory,
            FilesystemIterator::SKIP_DOTS,
        ));

        $names = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();

            if (! str_ends_with($path, $suffix)) {
                continue;
            }

            $name = substr($path, strlen($this->directory) + 1, -strlen($suffix));

            if (str_starts_with($name, self::INTERNAL_DIRECTORY.'/')) {
                continue;
            }

            if (str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }

        sort($names);

        yield from $names;
    }

    public function describe(string $key): string
    {
        return $this->path($key);
    }

    public function lock(string $key): void
    {
        if (isset($this->locks[$key])) {
            return;
        }

        $path = $this->lockPath($key);
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory %s.', $directory));
        }

        $this->ignoreInternalDirectory();

        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Could not open the lock file %s.', $path));
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new RuntimeException(sprintf('Could not lock %s.', $path));
        }

        $this->locks[$key] = $handle;
    }

    public function unlock(string $key): void
    {
        $handle = $this->locks[$key] ?? null;

        if ($handle === null) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
        unset($this->locks[$key]);
    }

    /**
     * The directory ignores itself, so nothing about lock files reaches a project's
     * version control settings — no line to add, and no .gitignore of the project's own
     * to edit behind its author's back.
     */
    private function ignoreInternalDirectory(): void
    {
        $gitignore = $this->directory.'/'.self::INTERNAL_DIRECTORY.'/.gitignore';

        if (! file_exists($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }
    }

    /**
     * Same name as the cassette, in the library's own directory: the lock is never renamed
     * or removed, so its inode stays put while the cassette's is replaced under it.
     */
    private function lockPath(string $key): string
    {
        return $this->directory.'/'.self::INTERNAL_DIRECTORY.'/'.$this->relativePath($key);
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.$this->relativePath($key);
    }

    /**
     * A cassette name is a relative path inside the directory, so `/` is meaningful and
     * sanitization applies per segment. Everything outside `[A-Za-z0-9_.-]` becomes `_`;
     * an empty segment, `.`, `..` or a hidden file is refused outright rather than
     * mangled, which is what keeps a name from resolving outside the directory.
     */
    private function relativePath(string $key): string
    {
        $segments = [];

        foreach (explode('/', $key) as $segment) {
            $sanitized = (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $segment);

            if ($sanitized === '' || str_starts_with($sanitized, '.')) {
                throw new InvalidArgumentException(sprintf(
                    'Cassette name "%s" has an unusable path segment "%s".',
                    $key,
                    $segment,
                ));
            }

            $segments[] = $sanitized;
        }

        return implode('/', $segments);
    }
}
