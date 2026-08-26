<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use HttpVcr\SecretFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretFinding::class)]
final class SecretFindingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function values(): iterable
    {
        yield 'a quarter of the value' => [
            'tk_live_9f8e7d6c5b4a3210FEDCBA',
            'tk_live…',
        ];

        yield 'capped at eight characters however long the value' => [
            'tk_live_9f8e7d6c5b4a3210FEDCBA9f8e7d6c5b4a3210FEDCBA',
            'tk_live_…',
        ];

        yield 'a quarter of a short one is fewer characters' => [
            'abcdefghijklmn',
            'abc…',
        ];

        yield 'never the whole value, however short' => [
            'hunter2',
            'h…',
        ];

        yield 'a value too short for a quarter still shows one character' => [
            'ab',
            'a…',
        ];
    }

    #[DataProvider('values')]
    public function testShowsAProportionOfTheValueRatherThanAFixedPrefix(string $value, string $excerpt): void
    {
        self::assertSame($excerpt, (new SecretFinding('response.body', $value))->excerpt());
    }

    public function testReportsHowLongTheValueWasSoTheExcerptCanBeFoundInTheCassette(): void
    {
        self::assertSame(30, (new SecretFinding('response.body', 'tk_live_9f8e7d6c5b4a3210FEDCBA'))->length());
    }
}
