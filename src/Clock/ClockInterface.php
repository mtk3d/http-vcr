<?php

declare(strict_types=1);

namespace HttpVcr\Clock;

use DateTimeImmutable;

/**
 * The source of "now" for recording timestamps and staleness checks.
 *
 * Injectable so a test — the library's own or a consumer's — can decide what time it is
 * instead of waiting for it.
 */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
