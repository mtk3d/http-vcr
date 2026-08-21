<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Matching;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Matching\BodyMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BodyMatcher::class)]
final class BodyMatcherTest extends TestCase
{
    private static function request(string $body, ?string $encoding = null): RecordedRequest
    {
        return new RecordedRequest('POST', 'https://example.com/orders', [], $body, $encoding);
    }

    public function testMatchesAnIdenticalBody(): void
    {
        $matcher = new BodyMatcher();

        self::assertTrue($matcher->matches(self::request('{"a":1}'), self::request('{"a":1}')));
        self::assertNull($matcher->explainMismatch(self::request('{"a":1}'), self::request('{"a":1}')));
    }

    /**
     * Byte-for-byte is the whole point of this matcher — the semantic comparison is
     * BodyJsonMatcher's job.
     */
    public function testRejectsABodyThatDiffersOnlyInFormatting(): void
    {
        self::assertFalse((new BodyMatcher())->matches(self::request('{"a":1}'), self::request('{ "a": 1 }')));
    }

    public function testShowsBothBodiesWhenTheyDiffer(): void
    {
        self::assertSame(
            'expected "{"a":1}", got "{"a":2}"',
            (new BodyMatcher())->explainMismatch(self::request('{"a":1}'), self::request('{"a":2}')),
        );
    }

    public function testTruncatesALongBodyInTheMessage(): void
    {
        self::assertSame(
            'expected "' . str_repeat('a', 60) . '…", got "b"',
            (new BodyMatcher())->explainMismatch(self::request(str_repeat('a', 200)), self::request('b')),
        );
    }

    public function testReportsSizesRatherThanBytesForABinaryBody(): void
    {
        self::assertSame(
            'binary body: expected 4 bytes, got 3',
            (new BodyMatcher())->explainMismatch(
                self::request("\x00\x01\x02\x03", 'base64'),
                self::request("\x00\x01\x02", 'base64'),
            ),
        );
    }

    public function testMatchesAnIdenticalBinaryBody(): void
    {
        self::assertTrue((new BodyMatcher())->matches(
            self::request("\x00\x01", 'base64'),
            self::request("\x00\x01", 'base64'),
        ));
    }
}
