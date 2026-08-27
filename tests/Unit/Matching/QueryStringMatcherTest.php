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

    public function testAnIgnoredParameterCountsAsEqualWhateverItHolds(): void
    {
        $matcher = (new QueryStringMatcher)->ignoreQueryParam('timestamp');

        self::assertTrue($matcher->matches(
            new RecordedRequest('GET', 'https://example.com/o?id=7&timestamp=1755000000'),
            new RecordedRequest('GET', 'https://example.com/o?id=7&timestamp=1766000000'),
        ));
    }

    public function testAnIgnoredParameterCountsAsEqualWhenItIsThereOnOnlyOneSide(): void
    {
        $matcher = (new QueryStringMatcher)->ignoreQueryParam('nonce');

        self::assertTrue($matcher->matches(
            new RecordedRequest('GET', 'https://example.com/o?id=7&nonce=abc'),
            new RecordedRequest('GET', 'https://example.com/o?id=7'),
        ));
    }

    public function testIgnoringOneParameterLeavesTheRestCompared(): void
    {
        $matcher = (new QueryStringMatcher)->ignoreQueryParam('timestamp');

        self::assertSame(
            'parameter "id" expected "7", got "8"',
            $matcher->explainMismatch(
                new RecordedRequest('GET', 'https://example.com/o?id=7&timestamp=1'),
                new RecordedRequest('GET', 'https://example.com/o?id=8&timestamp=2'),
            ),
        );
    }

    public function testNamingTheParametersToMatchOnIgnoresEveryOtherOne(): void
    {
        $matcher = (new QueryStringMatcher)->matchOnlyQueryParams(['id', 'version']);

        self::assertTrue($matcher->matches(
            new RecordedRequest('GET', 'https://example.com/o?id=7&version=2&signature=aaa&ts=1'),
            new RecordedRequest('GET', 'https://example.com/o?id=7&version=2&signature=bbb'),
        ));

        self::assertSame(
            'parameter "version" expected "2", got "3"',
            $matcher->explainMismatch(
                new RecordedRequest('GET', 'https://example.com/o?id=7&version=2&signature=aaa'),
                new RecordedRequest('GET', 'https://example.com/o?id=7&version=3&signature=aaa'),
            ),
        );
    }

    public function testAParameterNamedToMatchOnIsStillRequiredToBeThere(): void
    {
        $matcher = (new QueryStringMatcher)->matchOnlyQueryParams(['id']);

        self::assertSame(
            'parameter "id" missing',
            $matcher->explainMismatch(
                new RecordedRequest('GET', 'https://example.com/o?id=7'),
                new RecordedRequest('GET', 'https://example.com/o?signature=aaa'),
            ),
        );
    }

    /**
     * The convention every configurable matcher follows: configuring returns a new matcher,
     * so one built in a `matchers:` array stays a value rather than something later calls
     * can reach back into.
     */
    public function testConfiguringLeavesTheMatcherItWasCalledOnAlone(): void
    {
        $matcher = new QueryStringMatcher;
        $matcher->ignoreQueryParam('timestamp');

        self::assertFalse($matcher->matches(
            new RecordedRequest('GET', 'https://example.com/o?timestamp=1'),
            new RecordedRequest('GET', 'https://example.com/o?timestamp=2'),
        ));
    }
}
