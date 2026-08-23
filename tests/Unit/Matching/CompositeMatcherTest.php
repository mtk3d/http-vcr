<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Matching;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Matching\CompositeMatcher;
use HttpVcr\Matching\MethodMatcher;
use HttpVcr\Matching\Mismatch;
use HttpVcr\Matching\QueryStringMatcher;
use HttpVcr\Matching\RequestMatcherInterface;
use HttpVcr\Matching\UriMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeMatcher::class)]
#[CoversClass(Mismatch::class)]
final class CompositeMatcherTest extends TestCase
{
    public function testMatchesOnlyWhenEveryMatcherAccepts(): void
    {
        $matcher = $this->defaultSet();

        self::assertTrue($matcher->matches(
            new RecordedRequest('GET', 'https://example.com/products?page=1'),
            new RecordedRequest('GET', 'https://example.com/products?page=1'),
        ));
        self::assertFalse($matcher->matches(
            new RecordedRequest('GET', 'https://example.com/products?page=1'),
            new RecordedRequest('GET', 'https://example.com/products?page=2'),
        ));
    }

    public function testReportsTheFirstMatcherThatRejected(): void
    {
        $mismatch = $this->defaultSet()->explainMismatch(
            new RecordedRequest('GET', 'https://example.com/products?page=1'),
            new RecordedRequest('POST', 'https://example.com/orders?page=2'),
        );

        self::assertNotNull($mismatch);
        self::assertSame('MethodMatcher: expected GET, got POST', $mismatch->describe());
    }

    public function testStopsAtTheRejectingMatcherRatherThanCollectingEveryOpinion(): void
    {
        $mismatch = $this->defaultSet()->explainMismatch(
            new RecordedRequest('GET', 'https://example.com/products?page=1'),
            new RecordedRequest('GET', 'https://example.com/orders?page=2'),
        );

        self::assertNotNull($mismatch);
        self::assertSame('UriMatcher: expected path "/products"', $mismatch->describe());
    }

    public function testAMatcherThatCannotExplainItselfIsReportedByNameAlone(): void
    {
        $silent = new class implements RequestMatcherInterface
        {
            public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
            {
                return false;
            }
        };

        $mismatch = CompositeMatcher::of([$silent])->explainMismatch(
            new RecordedRequest('GET', 'https://example.com'),
            new RecordedRequest('GET', 'https://example.com'),
        );

        self::assertNotNull($mismatch);
        self::assertSame('RequestMatcherInterface@anonymous', $mismatch->describe());
    }

    public function testAnEmptyCompositionMatchesAnything(): void
    {
        self::assertTrue(CompositeMatcher::of([])->matches(
            new RecordedRequest('GET', 'https://example.com'),
            new RecordedRequest('POST', 'https://other.example.com'),
        ));
    }

    private function defaultSet(): CompositeMatcher
    {
        return new CompositeMatcher(new MethodMatcher, new UriMatcher, new QueryStringMatcher);
    }
}
