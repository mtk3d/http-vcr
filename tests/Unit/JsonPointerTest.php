<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use HttpVcr\JsonPointer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonPointer::class)]
final class JsonPointerTest extends TestCase
{
    private static function document(string $json = '{"customer":{"email":"a@example.com"},"tags":["a","b"]}'): mixed
    {
        return json_decode($json);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function pointers(): iterable
    {
        yield 'one token' => ['/email', ['email']];
        yield 'nested' => ['/customer/email', ['customer', 'email']];
        yield 'array index' => ['/tags/1', ['tags', '1']];
        yield 'escaped slash' => ['/a~1b', ['a/b']];
        yield 'escaped tilde' => ['/a~0b', ['a~b']];
        yield 'without the leading slash' => ['email', ['email']];
    }

    /**
     * @param list<string> $tokens
     */
    #[DataProvider('pointers')]
    public function testParsesReferenceTokens(string $pointer, array $tokens): void
    {
        self::assertSame($tokens, JsonPointer::tokens($pointer));
    }

    public function testReadsAValue(): void
    {
        self::assertSame(['a@example.com'], JsonPointer::read(self::document(), ['customer', 'email']));
        self::assertSame(['b'], JsonPointer::read(self::document(), ['tags', '1']));
    }

    /**
     * A member holding null and an absent member are different things, and a redaction rule
     * has to be able to tell them apart.
     */
    public function testDistinguishesANullMemberFromAnAbsentOne(): void
    {
        self::assertSame([null], JsonPointer::read(json_decode('{"a":null}'), ['a']));
        self::assertNull(JsonPointer::read(json_decode('{"a":null}'), ['b']));
    }

    public function testReplacesAValueWithoutTouchingTheOriginal(): void
    {
        $document = self::document();
        $changed = JsonPointer::with($document, ['customer', 'email'], '<REDACTED>');

        self::assertSame('{"customer":{"email":"<REDACTED>"},"tags":["a","b"]}', json_encode($changed));
        self::assertSame('{"customer":{"email":"a@example.com"},"tags":["a","b"]}', json_encode($document));
    }

    public function testInventsNothingWhereThereIsNoMember(): void
    {
        $document = self::document();

        self::assertSame(json_encode($document), json_encode(JsonPointer::with($document, ['customer', 'phone'], 'x')));
        self::assertSame(json_encode($document), json_encode(JsonPointer::without($document, ['customer', 'phone'])));
    }

    public function testRemovesAMember(): void
    {
        self::assertSame(
            '{"customer":{},"tags":["a","b"]}',
            json_encode(JsonPointer::without(self::document(), ['customer', 'email'])),
        );
    }

    public function testRemovingAnArrayElementClosesTheGap(): void
    {
        self::assertSame(
            '{"customer":{"email":"a@example.com"},"tags":["b"]}',
            json_encode(JsonPointer::without(self::document(), ['tags', '0'])),
        );
    }
}
