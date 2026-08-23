<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

/**
 * An immutable snapshot of a transport failure — what a cassette keeps in place of a
 * response.
 */
final readonly class RecordedError
{
    /**
     * @param  string  $exceptionClass  the original exception's class name, kept as diagnostic
     *                                  metadata; replay never tries to rebuild that class
     */
    public function __construct(
        public ErrorCategory $category,
        public string $message,
        public string $exceptionClass,
    ) {}

    public function withMessage(string $message): self
    {
        return new self($this->category, $message, $this->exceptionClass);
    }
}
