<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Matching;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Matching\QueryStringMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryStringMatcher::class)]
final class QueryStringMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentQueries(): iterable
    {
        yield 'same order' => ['?a=1&b=2', '?a=1&b=2'];
        yield 'different order' => ['?a=1&b=2', '?b=2&a=1'];
        yield 'both empty' => ['', ''];
        yield 'repeated key in the same order' => ['?tag=a&tag=b', '?tag=a&tag=b'];
        yield 'encoded value' => ['?q=a%20b', '?q=a+b'];
        yield 'path is not its business' => ['/one?a=1', '/two?a=1'];
    }

    #[DataProvider('equivalentQueries')]
    public function testTreatsTheSameParametersAsAMatchWhateverTheirOrder(string $recorded, string $incoming): void
    {
        self::assertTrue((new QueryStringMatcher)->matches(
            new RecordedRequest('GET', 'https://example.com'.$recorded),
            new RecordedRequest('GET', 'https://example.com'.$incoming),
        ));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function differentQueries(): iterable
    {
        yield 'different value' => ['?page=1', '?page=2', 'parameter "page" expected "1", got "2"'];
        yield 'missing parameter' => ['?page=1', '', 'parameter "page" missing'];
        yield 'extra parameter' => ['', '?page=1', 'unexpected parameter "page"'];
        yield 'repeated key in another order' => [
            '?tag=a&tag=b',
            '?tag=b&tag=a',
            'parameter "tag" expected ["a", "b"], got ["b", "a"]',
        ];
        yield 'value against no value' => ['?flag=', '?flag', 'parameter "flag" expected "", got (no value)'];
    }

    #[DataProvider('differentQueries')]
    public function testRejectsDifferentParametersAndNamesTheOffendingOne(string $recorded, string $incoming, string $detail): void
    {
        $matcher = new QueryStringMatcher;
        $recordedRequest = new RecordedRequest('GET', 'https://example.com/products'.$recorded);
        $incomingRequest = new RecordedRequest('GET', 'https://example.com/products'.$incoming);

        self::assertFalse($matcher->matches($recordedRequest, $incomingRequest));
        self::assertSame($detail, $matcher->explainMismatch($recordedRequest, $incomingRequest));
    }
}
