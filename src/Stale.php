<?php

declare(strict_types=1);

namespace HttpVcr;

use DateInterval;

/**
 * The intervals `staleAfter` is actually given, under names that read at a glance (§3.7).
 *
 * `new DateInterval('P7D')` is precise and says nothing: a reader has to decode `P7D`
 * before knowing whether the recording is refreshed weekly or monthly, and the ISO-8601
 * duration is easy to write wrong in the direction that silences the check (`PT1M` is one
 * minute, `P1M` is one month). This covers the handful of intervals a recording is
 * realistically given; anything else is still a `DateInterval`, which every entry point
 * takes beside this enum.
 *
 * An enum rather than factory methods because the primary place this is written is a
 * PHP attribute, and an attribute argument must be a constant expression — `Stale::Week`
 * is one, `Stale::days(7)` is not.
 */
enum Stale
{
    case Day;
    case Week;
    case Month;
    case Quarter;
    case Year;

    public function interval(): DateInterval
    {
        return new DateInterval(match ($this) {
            self::Day => 'P1D',
            self::Week => 'P7D',
            self::Month => 'P1M',
            self::Quarter => 'P3M',
            self::Year => 'P1Y',
        });
    }

    /**
     * What every entry point taking `DateInterval|Stale|null` stores: the interval itself,
     * so nothing downstream has to know this enum exists.
     */
    public static function asInterval(DateInterval|self|null $staleAfter): ?DateInterval
    {
        return $staleAfter instanceof self ? $staleAfter->interval() : $staleAfter;
    }
}
