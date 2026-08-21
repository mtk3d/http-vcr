<?php

declare(strict_types=1);

namespace HttpVcr\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The default clock: the system time, in UTC.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
