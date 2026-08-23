<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Exception;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Exception\CassetteNotFoundException;
use HttpVcr\Exception\NoMatchingInteractionException;
use HttpVcr\Exception\VcrException;
use HttpVcr\Matching\Mismatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoMatchingInteractionException::class)]
#[CoversClass(CassetteNotFoundException::class)]
final class NoMatchingInteractionExceptionTest extends TestCase
{
    public function testTheMessageNamesTheRequestAndWhyEachUnconsumedInteractionWasRejected(): void
    {
        $exception = NoMatchingInteractionException::forRequest(
            new RecordedRequest('GET', 'https://shop.example.com/products/123.json'),
            'tests/Cassettes/shopify/get-product.json',
            [
                1 => new Mismatch('UriMatcher', 'expected path "/products/124.json"'),
                2 => new Mismatch('MethodMatcher', 'expected POST, got GET'),
            ],
            3,
        );

        self::assertSame(
            <<<'TEXT'
                No matching interaction for GET https://shop.example.com/products/123.json

                Cassette tests/Cassettes/shopify/get-product.json, 2 unconsumed interactions:
                  #1  UriMatcher: expected path "/products/124.json"
                  #2  MethodMatcher: expected POST, got GET
                TEXT,
            $exception->getMessage(),
        );
    }

    public function testTheInteractionNumbersAreItsPositionInTheCassetteNotInTheReport(): void
    {
        $exception = NoMatchingInteractionException::forRequest(
            new RecordedRequest('GET', 'https://example.com/a'),
            'cassette.json',
            [4 => new Mismatch('UriMatcher', 'expected path "/b"')],
            4,
        );

        self::assertStringContainsString('1 unconsumed interaction:', $exception->getMessage());
        self::assertStringContainsString('#4  UriMatcher', $exception->getMessage());
    }

    public function testAnAlreadyExhaustedCassetteSaysSoRatherThanListingNothing(): void
    {
        $exception = NoMatchingInteractionException::forRequest(
            new RecordedRequest('GET', 'https://example.com/a'),
            'cassette.json',
            [],
            2,
        );

        self::assertStringContainsString('Cassette cassette.json, all 2 interactions were already consumed.', $exception->getMessage());
    }

    public function testAMissingCassetteIsASpecializationSoBothTypesCatchIt(): void
    {
        $exception = CassetteNotFoundException::at(
            'tests/Cassettes/shopify/get-product.json',
            new RecordedRequest('GET', 'https://shop.example.com/products/123.json'),
        );

        self::assertInstanceOf(NoMatchingInteractionException::class, $exception);
        self::assertInstanceOf(VcrException::class, $exception);
        self::assertSame(
            'No cassette at tests/Cassettes/shopify/get-product.json to replay '
            .'GET https://shop.example.com/products/123.json from.',
            $exception->getMessage(),
        );
    }
}
