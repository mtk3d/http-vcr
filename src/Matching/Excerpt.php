<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

/**
 * Shortens a value for a mismatch message. A diagnostic that prints two 200 KB payloads
 * in full is not a diagnostic.
 *
 * @internal
 */
final class Excerpt
{
    private const LENGTH = 60;

    /**
     * Cut on a character boundary rather than a byte one — without reaching for mbstring,
     * which the core deliberately doesn't depend on.
     */
    public static function of(string $value): string
    {
        if (preg_match('/^.{0,' . self::LENGTH . '}/us', $value, $match) === 1) {
            return $match[0] === $value ? $value : $match[0] . '…';
        }

        return strlen($value) > self::LENGTH ? substr($value, 0, self::LENGTH) . '…' : $value;
    }
}
