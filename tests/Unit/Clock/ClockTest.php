<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Clock;

use DateTimeImmutable;
use HttpVcr\Clock\FrozenClock;
use HttpVcr\Clock\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(SystemClock::class)]
#[CoversClass(FrozenClock::class)]
final class ClockTest extends TestCase
{
    public function testBothClocksArePsr20Clocks(): void
    {
        self::assertInstanceOf(ClockInterface::class, new SystemClock);
        self::assertInstanceOf(ClockInterface::class, FrozenClock::at('2026-08-21T10:00:00+00:00'));
    }

    public function testSystemClockReportsTheCurrentTimeInUtc(): void
    {
        $now = (new SystemClock)->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertEqualsWithDelta(time(), $now->getTimestamp(), 5);
    }

    public function testFrozenClockStaysWhereItWasPut(): void
    {
        $clock = FrozenClock::at('2026-08-21T10:00:00+00:00');

        self::assertSame('2026-08-21T10:00:00+00:00', $clock->now()->format('c'));
        self::assertSame($clock->now(), $clock->now());
    }

    public function testFrozenClockCanBeMoved(): void
    {
        $clock = FrozenClock::at('2026-08-21T10:00:00+00:00');

        $clock->set(new DateTimeImmutable('2026-09-01T00:00:00+00:00'));

        self::assertSame('2026-09-01T00:00:00+00:00', $clock->now()->format('c'));
    }
}
