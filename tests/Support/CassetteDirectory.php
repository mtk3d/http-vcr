<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Support;

use HttpVcr\Persistence\FilesystemCassettePersister;

/**
 * A throwaway cassette directory for one test, with a couple of shortcuts for looking at
 * what actually landed on disk.
 */
final class CassetteDirectory
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/http-vcr-' . bin2hex(random_bytes(6));
    }

    public function persister(): FilesystemCassettePersister
    {
        return new FilesystemCassettePersister($this->path);
    }

    public function write(string $name, string $content): void
    {
        $path = $this->path . '/' . $name;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $content);
    }

    public function read(string $name): string
    {
        return (string) file_get_contents($this->path . '/' . $name);
    }

    public function cassette(string $name): CassetteFile
    {
        return new CassetteFile($this->read($name));
    }

    public function has(string $name): bool
    {
        return is_file($this->path . '/' . $name);
    }

    public function remove(): void
    {
        $this->removeDirectory($this->path);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
