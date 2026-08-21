<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Cassette;

use HttpVcr\Cassette\RecordedResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordedResponse::class)]
final class RecordedResponseTest extends TestCase
{
    public function testWithersLeaveTheOriginalUntouched(): void
    {
        $response = new RecordedResponse(200, ['Content-Type' => ['application/json']], '{}');

        $changed = $response->withStatus(404)->withBody('{"error":"not found"}');

        self::assertSame(200, $response->status);
        self::assertSame('{}', $response->body);
        self::assertSame(404, $changed->status);
        self::assertSame('{"error":"not found"}', $changed->body);
        self::assertSame(['Content-Type' => ['application/json']], $changed->headers);
    }

    public function testHeaderLookupIgnoresCase(): void
    {
        $response = new RecordedResponse(200, ['Set-Cookie' => ['a=1', 'b=2']]);

        self::assertSame(['a=1', 'b=2'], $response->header('set-cookie'));
    }
}
