<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Exception\MissingEnvironmentVariableException;
use HttpVcr\Provider;
use HttpVcr\RecordMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Credentials are checked on the recording branch and per request, which is what lets a
 * partial re-record ask only for the keys of the API it is refreshing (§3.12).
 */
#[CoversClass(MissingEnvironmentVariableException::class)]
#[CoversClass(VcrClient::class)]
final class RequiredEnvironmentTest extends TestCase
{
    use ControlsEnvironment;

    private CassetteDirectory $cassettes;

    protected function setUp(): void
    {
        $this->cassettes = new CassetteDirectory;

        $this->takeOverEnvironment(
            'VCR_ALLOW_RECORDING',
            'VCR_ERASE_TAPE',
            'CI',
            'SHOPIFY_API_KEY',
            'ZENDESK_API_KEY',
            'TENANT_ID',
        );
        $_ENV['VCR_ALLOW_RECORDING'] = '1';
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        Config::reset();
    }

    public function testAMissingProviderKeyStopsTheRequestBeforeItGoesOut(): void
    {
        $this->declareShopify();

        $inner = (new FakeHttpClient)->willRespond('{"title":"T-Shirt"}');
        $vcr = new VcrClient($inner, 'shopify/get-product', persister: $this->cassettes->persister());

        try {
            $vcr->sendRequest(new Request('GET', 'https://shop.myshopify.com/products/1.json'));
            self::fail('expected the missing key to stop the recording');
        } catch (MissingEnvironmentVariableException $exception) {
            self::assertSame(
                'Cannot record cassette "shopify/get-product": missing env var SHOPIFY_API_KEY '
                .'(required by provider "shopify").',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $inner->sentCount());
        self::assertFalse($this->cassettes->has('shopify/get-product.json'));
    }

    public function testThePresentKeyIsEnoughForTheRecordingToProceed(): void
    {
        $this->declareShopify();
        $_ENV['SHOPIFY_API_KEY'] = 'shpat_secret';

        $inner = (new FakeHttpClient)->willRespond('{"title":"T-Shirt"}');
        $vcr = new VcrClient($inner, 'shopify/get-product', persister: $this->cassettes->persister());

        $vcr->sendRequest(new Request('GET', 'https://shop.myshopify.com/products/1.json'));

        self::assertSame(1, $inner->sentCount());
    }

    public function testOnlyTheApiBeingRecordedHasToHaveItsCredentials(): void
    {
        $this->declareShopify();
        $_ENV['SHOPIFY_API_KEY'] = 'shpat_secret';

        $inner = (new FakeHttpClient)->willRespond('{"order":"new"}');
        $vcr = new VcrClient($inner, 'sync/order-flow', persister: $this->cassettes->persister());

        // Zendesk has a key requirement of its own and nothing is set for it — but nothing
        // is being recorded against it either.
        $vcr->sendRequest(new Request('GET', 'https://shop.myshopify.com/orders/1'));

        self::assertSame(1, $inner->sentCount());
    }

    public function testAReplayingRunNeverAsksForCredentialsItWillNotUse(): void
    {
        $this->declareShopify();
        $_ENV['SHOPIFY_API_KEY'] = 'shpat_secret';

        $inner = (new FakeHttpClient)->willRespond('{"title":"T-Shirt"}');
        $request = new Request('GET', 'https://shop.myshopify.com/products/1.json');

        (new VcrClient($inner, 'shopify/get-product', persister: $this->cassettes->persister()))
            ->sendRequest($request);

        unset($_ENV['SHOPIFY_API_KEY']);

        $replayed = (new VcrClient(null, 'shopify/get-product', RecordMode::PlaybackOnly, persister: $this->cassettes->persister()))
            ->sendRequest($request);

        self::assertSame('{"title":"T-Shirt"}', (string) $replayed->getBody());
    }

    public function testACassetteCanRequireAVariableOfItsOwnWithNoProviderInvolved(): void
    {
        $vcr = new VcrClient(
            new FakeHttpClient,
            'billing/charge',
            requiresEnv: ['TENANT_ID'],
            persister: $this->cassettes->persister(),
        );

        $this->expectException(MissingEnvironmentVariableException::class);
        $this->expectExceptionMessage(
            'Cannot record cassette "billing/charge": missing env var TENANT_ID (required by the cassette).',
        );

        $vcr->sendRequest(new Request('POST', 'https://api.stripe.com/v1/charges'));
    }

    public function testBothSourcesAreReportedTogetherRatherThanOneRunAtATime(): void
    {
        $this->declareShopify();

        $vcr = new VcrClient(
            new FakeHttpClient,
            'shopify/get-product',
            requiresEnv: ['TENANT_ID'],
            persister: $this->cassettes->persister(),
        );

        $this->expectException(MissingEnvironmentVariableException::class);
        $this->expectExceptionMessage(
            'missing env var SHOPIFY_API_KEY (required by provider "shopify"), '
            .'missing env var TENANT_ID (required by the cassette).',
        );

        $vcr->sendRequest(new Request('GET', 'https://shop.myshopify.com/products/1.json'));
    }

    private function declareShopify(): void
    {
        VcrClient::configure(providers: [
            'shopify' => new Provider(hosts: ['*.myshopify.com'], requiresEnv: ['SHOPIFY_API_KEY']),
            'zendesk' => new Provider(hosts: ['acme.zendesk.com'], requiresEnv: ['ZENDESK_API_KEY']),
        ]);
    }
}
