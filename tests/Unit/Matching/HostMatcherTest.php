<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Matching;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Matching\HostMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HostMatcher::class)]
final class HostMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sameHost(): iterable
    {
        yield 'identical' => ['https://example.com/products', 'https://example.com/products'];
        yield 'case' => ['https://Example.COM/products', 'https://example.com/products'];
        yield 'a different path is not its business' => ['https://example.com/products', 'https://example.com/orders/1'];
        yield 'a different scheme is not its business' => ['https://example.com/products', 'http://example.com/products'];
        yield 'a different port is not its business' => ['https://example.com:8443/products', 'https://example.com/products'];
    }

    #[DataProvider('sameHost')]
    public function testMatchesOnTheHostAlone(string $recorded, string $incoming): void
    {
        self::assertTrue((new HostMatcher)->matches(
            new RecordedRequest('GET', $recorded),
            new RecordedRequest('GET', $incoming),
        ));
    }

    public function testRejectsADifferentHostAndNamesTheExpectedOne(): void
    {
        $matcher = new HostMatcher;
        $recorded = new RecordedRequest('GET', 'https://example.com/products');
        $incoming = new RecordedRequest('GET', 'https://other.example.com/products');

        self::assertFalse($matcher->matches($recorded, $incoming));
        self::assertSame('expected host "example.com", got "other.example.com"', $matcher->explainMismatch($recorded, $incoming));
    }

    public function testAcceptsTheMatchingPairSilently(): void
    {
        $matcher = new HostMatcher;

        self::assertNull($matcher->explainMismatch(
            new RecordedRequest('GET', 'https://example.com/a'),
            new RecordedRequest('GET', 'https://example.com/b'),
        ));
    }
}
