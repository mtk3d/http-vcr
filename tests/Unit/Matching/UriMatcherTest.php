<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Matching;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Matching\UriMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UriMatcher::class)]
final class UriMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentUris(): iterable
    {
        yield 'identical' => ['https://example.com/products', 'https://example.com/products'];
        yield 'host case' => ['https://Example.COM/products', 'https://example.com/products'];
        yield 'scheme case' => ['HTTPS://example.com/products', 'https://example.com/products'];
        yield 'default https port' => ['https://example.com:443/products', 'https://example.com/products'];
        yield 'default http port' => ['http://example.com:80/products', 'http://example.com/products'];
        yield 'root path spelled two ways' => ['https://example.com', 'https://example.com/'];
        yield 'query is not its business' => ['https://example.com/products?page=1', 'https://example.com/products?page=2'];
    }

    #[DataProvider('equivalentUris')]
    public function testTreatsEquivalentSpellingsAsTheSameUri(string $recorded, string $incoming): void
    {
        self::assertTrue((new UriMatcher())->matches(
            new RecordedRequest('GET', $recorded),
            new RecordedRequest('GET', $incoming),
        ));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function differentUris(): iterable
    {
        yield 'path' => [
            'https://example.com/products/123',
            'https://example.com/products/124',
            'expected path "/products/123"',
        ];
        yield 'trailing slash off the root' => [
            'https://example.com/products/',
            'https://example.com/products',
            'expected path "/products/"',
        ];
        yield 'host' => [
            'https://example.com/products',
            'https://other.example.com/products',
            'expected host "example.com"',
        ];
        yield 'scheme' => [
            'https://example.com/products',
            'http://example.com/products',
            'expected scheme "https"',
        ];
        yield 'non-default port' => [
            'https://example.com:8443/products',
            'https://example.com/products',
            'expected port "8443"',
        ];
        yield 'percent encoding is compared as written' => [
            'https://example.com/a%20b',
            'https://example.com/a+b',
            'expected path "/a%20b"',
        ];
    }

    #[DataProvider('differentUris')]
    public function testRejectsADifferentUriAndNamesTheComponent(string $recorded, string $incoming, string $detail): void
    {
        $matcher = new UriMatcher();
        $recordedRequest = new RecordedRequest('GET', $recorded);
        $incomingRequest = new RecordedRequest('GET', $incoming);

        self::assertFalse($matcher->matches($recordedRequest, $incomingRequest));
        self::assertSame($detail, $matcher->explainMismatch($recordedRequest, $incomingRequest));
    }
}
