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
    /** @var array<string, resource> */
    private array $locks = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function read(string $key): ?string
    {
        $path = $this->path($key);

        if (!is_file($path)) {
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

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory %s.', $directory));
        }

        $temporary = tempnam($directory, '.http-vcr-');

        if ($temporary === false) {
            throw new RuntimeException(sprintf('Could not create a temporary file in %s.', $directory));
        }

        if (file_put_contents($temporary, $content) === false || !rename($temporary, $path)) {
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
        if (!is_dir($this->directory)) {
            return;
        }

        $suffix = '.' . $extension;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->directory,
            FilesystemIterator::SKIP_DOTS,
        ));

        $names = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();

            if (!str_ends_with($path, $suffix)) {
                continue;
            }

            $name = substr($path, strlen($this->directory) + 1, -strlen($suffix));

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

        $path = $this->path($key);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory %s.', $directory));
        }

        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Could not open the lock file %s.', $path));
        }

        if (!flock($handle, LOCK_EX)) {
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
     * A cassette name is a relative path inside the directory, so `/` is meaningful and
     * sanitization applies per segment. Everything outside `[A-Za-z0-9_.-]` becomes `_`;
     * an empty segment, `.`, `..` or a hidden file is refused outright rather than
     * mangled, and the resolved path is checked to still be inside the directory.
     */
    private function path(string $key): string
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

        return $this->directory . '/' . implode('/', $segments);
    }
}
