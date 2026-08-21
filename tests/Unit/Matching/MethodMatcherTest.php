<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Matching;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Matching\MethodMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodMatcher::class)]
final class MethodMatcherTest extends TestCase
{
    public function testMatchesTheSameMethodWhateverItsCase(): void
    {
        $matcher = new MethodMatcher();

        self::assertTrue($matcher->matches(
            new RecordedRequest('GET', 'https://example.com'),
            new RecordedRequest('get', 'https://example.com'),
        ));
    }

    public function testRejectsADifferentMethodAndSaysWhich(): void
    {
        $matcher = new MethodMatcher();
        $recorded = new RecordedRequest('GET', 'https://example.com');
        $incoming = new RecordedRequest('POST', 'https://example.com');

        self::assertFalse($matcher->matches($recorded, $incoming));
        self::assertSame('expected GET, got POST', $matcher->explainMismatch($recorded, $incoming));
    }
}
