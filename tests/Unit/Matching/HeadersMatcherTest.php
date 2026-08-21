<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Matching;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Matching\HeadersMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeadersMatcher::class)]
final class HeadersMatcherTest extends TestCase
{
    /**
     * @param array<string, list<string>> $headers
     */
    private static function request(array $headers): RecordedRequest
    {
        return new RecordedRequest('GET', 'https://example.com/products', $headers);
    }

    public function testComparesTheNamedHeadersOnly(): void
    {
        $matcher = new HeadersMatcher(['X-Shop-Domain']);

        self::assertTrue($matcher->matches(
            self::request(['X-Shop-Domain' => ['acme.myshopify.com'], 'Accept' => ['application/json']]),
            self::request(['X-Shop-Domain' => ['acme.myshopify.com'], 'Accept' => ['text/plain']]),
        ));
    }

    public function testFoldsHeaderNameCase(): void
    {
        $matcher = new HeadersMatcher(['content-type']);

        self::assertTrue($matcher->matches(
            self::request(['Content-Type' => ['application/json']]),
            self::request(['CONTENT-TYPE' => ['application/json']]),
        ));
    }

    public function testComparesEveryRecordedHeaderWhenNoneAreNamed(): void
    {
        $matcher = new HeadersMatcher();

        self::assertTrue($matcher->matches(
            self::request(['Accept' => ['application/json']]),
            self::request(['Accept' => ['application/json']]),
        ));
        self::assertFalse($matcher->matches(
            self::request(['Accept' => ['application/json']]),
            self::request(['Accept' => ['text/plain']]),
        ));
    }

    /**
     * The whole reason the default isn't a 1:1 comparison: Guzzle and Symfony each add
     * headers of their own, so swapping HTTP client libraries would otherwise break every
     * cassette in a project that never mentioned those headers.
     */
    public function testIgnoresHeadersTheIncomingRequestAddedOnItsOwn(): void
    {
        $matcher = new HeadersMatcher();

        self::assertTrue($matcher->matches(
            self::request(['Accept' => ['application/json']]),
            self::request(['Accept' => ['application/json'], 'User-Agent' => ['GuzzleHttp/7'], 'Accept-Encoding' => ['gzip']]),
        ));
    }

    public function testKeepsRepeatedValuesAndTheirOrderSignificant(): void
    {
        $matcher = new HeadersMatcher(['X-Tag']);

        self::assertFalse($matcher->matches(
            self::request(['X-Tag' => ['a', 'b']]),
            self::request(['X-Tag' => ['b', 'a']]),
        ));
    }

    public function testExactModeRejectsAnExtraHeader(): void
    {
        $matcher = new HeadersMatcher(exact: true);
        $recorded = self::request(['Accept' => ['application/json']]);
        $incoming = self::request(['Accept' => ['application/json'], 'User-Agent' => ['GuzzleHttp/7']]);

        self::assertFalse($matcher->matches($recorded, $incoming));
        self::assertSame('unexpected header "user-agent"', $matcher->explainMismatch($recorded, $incoming));
    }

    public function testExactModeStaysWithinTheNamedHeaders(): void
    {
        $matcher = new HeadersMatcher(['Accept'], exact: true);

        self::assertTrue($matcher->matches(
            self::request(['Accept' => ['application/json']]),
            self::request(['Accept' => ['application/json'], 'User-Agent' => ['GuzzleHttp/7']]),
        ));
        self::assertFalse($matcher->matches(
            self::request([]),
            self::request(['Accept' => ['application/json']]),
        ));
    }

    public function testNamesTheHeaderThatIsMissing(): void
    {
        $matcher = new HeadersMatcher();
        $recorded = self::request(['X-Shop-Domain' => ['acme.myshopify.com']]);
        $incoming = self::request([]);

        self::assertFalse($matcher->matches($recorded, $incoming));
        self::assertSame('header "x-shop-domain" missing', $matcher->explainMismatch($recorded, $incoming));
    }

    public function testNamesTheHeaderThatDiffersAndBothValues(): void
    {
        $matcher = new HeadersMatcher();
        $recorded = self::request(['Accept' => ['application/json']]);
        $incoming = self::request(['Accept' => ['text/plain']]);

        self::assertSame(
            'header "accept" expected "application/json", got "text/plain"',
            $matcher->explainMismatch($recorded, $incoming),
        );
    }

    public function testDescribesRepeatedValuesAsAList(): void
    {
        $matcher = new HeadersMatcher();

        self::assertSame(
            'header "x-tag" expected ["a", "b"], got ["b", "a"]',
            $matcher->explainMismatch(
                self::request(['X-Tag' => ['a', 'b']]),
                self::request(['X-Tag' => ['b', 'a']]),
            ),
        );
    }

    public function testExplainsNothingWhenTheHeadersAgree(): void
    {
        self::assertNull((new HeadersMatcher())->explainMismatch(
            self::request(['Accept' => ['application/json']]),
            self::request(['Accept' => ['application/json']]),
        ));
    }
}
