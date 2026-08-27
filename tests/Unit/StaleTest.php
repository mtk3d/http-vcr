<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use HttpVcr\Stale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Stale::class)]
final class StaleTest extends TestCase
{
    /**
     * @return iterable<string, array{Stale, string}>
     */
    public static function intervals(): iterable
    {
        yield 'day' => [Stale::Day, '2026-08-22'];
        yield 'week' => [Stale::Week, '2026-08-28'];
        yield 'month' => [Stale::Month, '2026-09-21'];
        yield 'quarter' => [Stale::Quarter, '2026-11-21'];
        yield 'year' => [Stale::Year, '2027-08-21'];
    }

    /**
     * Asserted by adding the interval to a date rather than by reading its fields back: a
     * month is what a calendar says it is, and that is the property `staleAfter` needs.
     */
    #[DataProvider('intervals')]
    public function testEachCaseIsTheIntervalItsNameClaims(Stale $stale, string $expected): void
    {
        $recordedAt = new DateTimeImmutable('2026-08-21 00:00:00');

        self::assertSame($expected, $recordedAt->add($stale->interval())->format('Y-m-d'));
    }

    public function testAnIntervalPassesThroughUntouched(): void
    {
        $interval = new DateInterval('PT30M');

        self::assertSame($interval, Stale::asInterval($interval));
    }

    public function testNothingStaysNothing(): void
    {
        self::assertNull(Stale::asInterval(null));
    }

    public function testACaseBecomesItsInterval(): void
    {
        self::assertSame(7, Stale::asInterval(Stale::Week)?->d);
    }
}
