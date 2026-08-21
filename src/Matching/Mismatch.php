<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

/**
 * One interaction's rejection: the first matcher that turned it down, and what it said.
 */
final readonly class Mismatch
{
    public function __construct(
        public string $matcher,
        public ?string $detail = null,
    ) {
    }

    public static function from(RequestMatcherInterface $matcher, ?string $detail = null): self
    {
        $name = $matcher::class;

        // An anonymous class carries the file and line it was declared in, after a NUL byte.
        $nul = strpos($name, "\0");
        $name = $nul === false ? $name : substr($name, 0, $nul);

        $shortName = strrchr($name, '\\');

        return new self($shortName === false ? $name : substr($shortName, 1), $detail);
    }

    public function describe(): string
    {
        return $this->detail === null ? $this->matcher : $this->matcher . ': ' . $this->detail;
    }
}
