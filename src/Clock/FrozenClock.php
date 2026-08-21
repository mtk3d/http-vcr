<?php

declare(strict_types=1);

namespace HttpVcr\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock that stays where it was put.
 *
 * Shipped with the package rather than kept in the library's own tests: a consumer
 * testing what their cassettes do over time needs the same thing.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public static function at(string $time): self
    {
        return new self(new DateTimeImmutable($time));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
