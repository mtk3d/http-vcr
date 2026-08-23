<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A stream that can be read exactly once — what a multipart upload or a streamed response
 * looks like from PSR-7's side.
 */
final class UnrewindableStream implements StreamInterface
{
    private bool $read = false;

    public function __construct(private string $content) {}

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('This stream cannot be seeked.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('This stream cannot be rewound.');
    }

    public function getContents(): string
    {
        if ($this->read) {
            return '';
        }

        $this->read = true;

        return $this->content;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function read(int $length): string
    {
        return substr($this->getContents(), 0, $length);
    }

    public function eof(): bool
    {
        return $this->read;
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return $this->read ? strlen($this->content) : 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('This stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
