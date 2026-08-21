<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use DateTimeImmutable;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\EraseTape;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EraseTape::class)]
final class EraseTapeTest extends TestCase
{
    public function testAnUnsetVariableErasesNothing(): void
    {
        $eraseTape = EraseTape::parse(null);

        self::assertFalse($eraseTape->isActive());
        self::assertFalse($eraseTape->covers('shopify/get-product'));
    }

    public function testASelectorCoversOnlyTheCassetteItNames(): void
    {
        $eraseTape = EraseTape::parse('shopify/get-product');

        self::assertTrue($eraseTape->covers('shopify/get-product'));
        self::assertFalse($eraseTape->covers('shopify/list-products'));
    }

    public function testACommaSeparatedListCoversEachCassetteInIt(): void
    {
        $eraseTape = EraseTape::parse('shopify/get-product, shopify/list-products');

        self::assertTrue($eraseTape->covers('shopify/get-product'));
        self::assertTrue($eraseTape->covers('shopify/list-products'));
        self::assertFalse($eraseTape->covers('zendesk/get-ticket'));
    }

    public function testAllCoversEveryCassetteTheRunOpens(): void
    {
        self::assertTrue(EraseTape::parse('all')->covers('anything/at-all'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bareBooleans(): iterable
    {
        yield '1' => ['1'];
        yield '0' => ['0'];
        yield 'true' => ['true'];
        yield 'false' => ['false'];
    }

    #[DataProvider('bareBooleans')]
    public function testABareBooleanIsRefusedRatherThanGuessedAt(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VCR_ERASE_TAPE takes cassette selectors, not "' . $value . '"');

        EraseTape::parse($value);
    }

    public function testASelectorWithNothingAfterTheAtSignIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EraseTape::parse('shopify/get-product@');
    }

    public function testEverythingUnlockedIsErasedWhenNoApiIsNamed(): void
    {
        $eraseTape = EraseTape::parse('sync/order-flow');

        self::assertFalse($eraseTape->spares('sync/order-flow', $this->interaction('https://shop.myshopify.com/products')));
        self::assertFalse($eraseTape->spares('sync/order-flow', $this->interaction('https://acme.zendesk.com/tickets')));
    }

    public function testNamingAnApiSparesEveryOtherApiInTheSameCassette(): void
    {
        $eraseTape = EraseTape::parse('@shop.myshopify.com');

        self::assertFalse($eraseTape->spares('sync/order-flow', $this->interaction('https://shop.myshopify.com/products')));
        self::assertTrue($eraseTape->spares('sync/order-flow', $this->interaction('https://acme.zendesk.com/tickets')));
    }

    public function testTheApiHalfCanBeCombinedWithACassetteName(): void
    {
        $eraseTape = EraseTape::parse('sync/order-flow@shop.myshopify.com');

        self::assertTrue($eraseTape->covers('sync/order-flow'));
        self::assertFalse($eraseTape->covers('other/cassette'));
        self::assertFalse($eraseTape->spares('sync/order-flow', $this->interaction('https://shop.myshopify.com/products')));
    }

    public function testAllAtProviderIsTheExplicitSpellingOfAtProvider(): void
    {
        $eraseTape = EraseTape::parse('all@shop.myshopify.com');

        self::assertTrue($eraseTape->covers('any/cassette'));
        self::assertFalse($eraseTape->spares('any/cassette', $this->interaction('https://shop.myshopify.com/products')));
        self::assertTrue($eraseTape->spares('any/cassette', $this->interaction('https://acme.zendesk.com/tickets')));
    }

    public function testALockedInteractionSurvivesEverySelector(): void
    {
        $locked = $this->interaction('https://shop.myshopify.com/orders')->withLocked(true);

        self::assertTrue(EraseTape::parse('all')->spares('sync/order-flow', $locked));
        self::assertTrue(EraseTape::parse('sync/order-flow')->spares('sync/order-flow', $locked));
        self::assertTrue(EraseTape::parse('@shop.myshopify.com')->spares('sync/order-flow', $locked));
    }

    private function interaction(string $uri): Interaction
    {
        return new Interaction(
            new RecordedRequest('GET', $uri),
            new RecordedResponse(200),
            new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
        );
    }
}
