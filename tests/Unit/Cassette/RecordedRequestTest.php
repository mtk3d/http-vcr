<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Cassette;

use HttpVcr\Cassette\RecordedRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordedRequest::class)]
final class RecordedRequestTest extends TestCase
{
    public function testWithersLeaveTheOriginalUntouched(): void
    {
        $request = new RecordedRequest('GET', 'https://example.com/a', ['Accept' => ['application/json']], '');

        $changed = $request->withUri('https://example.com/b')->withBody('{"a":1}');

        self::assertSame('https://example.com/a', $request->uri);
        self::assertSame('', $request->body);
        self::assertSame('https://example.com/b', $changed->uri);
        self::assertSame('{"a":1}', $changed->body);
        self::assertSame(['Accept' => ['application/json']], $changed->headers);
    }

    public function testWithHeaderReplacesAnExistingNameWhateverItsCase(): void
    {
        $request = (new RecordedRequest('GET', 'https://example.com'))
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('content-type', 'application/json');

        self::assertSame(['content-type' => ['application/json']], $request->headers);
    }

    public function testHeaderLookupAndRemovalIgnoreCase(): void
    {
        $request = new RecordedRequest('GET', 'https://example.com', ['Authorization' => ['Bearer x']]);

        self::assertSame(['Bearer x'], $request->header('authorization'));
        self::assertSame([], $request->withoutHeader('AUTHORIZATION')->header('Authorization'));
    }

    public function testHeaderReturnsEmptyListWhenAbsent(): void
    {
        self::assertSame([], (new RecordedRequest('GET', 'https://example.com'))->header('Accept'));
    }

    public function testWithHeaderAcceptsAListOfValues(): void
    {
        $request = (new RecordedRequest('GET', 'https://example.com'))->withHeader('X-Tag', ['a', 'b']);

        self::assertSame(['a', 'b'], $request->header('X-Tag'));
    }
}
