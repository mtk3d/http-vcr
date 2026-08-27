<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use HttpVcr\Clock\FrozenClock;
use HttpVcr\Config;
use HttpVcr\Matching\MethodMatcher;
use HttpVcr\Matching\QueryStringMatcher;
use HttpVcr\Matching\UriMatcher;
use HttpVcr\Persistence\FilesystemCassettePersister;
use HttpVcr\Provider;
use HttpVcr\Serializer\JsonCassetteSerializer;
use HttpVcr\Serializer\YamlCassetteSerializer;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\Tests\Support\InMemoryCassettePersister;
use HttpVcr\VcrClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testCassettesDefaultToTestsCassettesUnderTheProjectRoot(): void
    {
        self::assertStringEndsWith('/tests/Cassettes', Config::create()->cassetteDirectory());
        self::assertFileExists(dirname(Config::create()->cassetteDirectory(), 2).'/composer.json');
    }

    public function testTheDefaultsAreTheFilesystemMatchedOnMethodUriAndQueryString(): void
    {
        $config = Config::create();

        self::assertInstanceOf(FilesystemCassettePersister::class, $config->persister());
        self::assertEquals(
            [new MethodMatcher, new UriMatcher, new QueryStringMatcher],
            $config->defaultMatchers(),
        );
    }

    public function testTheDefaultFormatIsYamlWhereSymfonyYamlIsInstalledAndJsonWhereItIsNot(): void
    {
        self::assertInstanceOf(
            class_exists(Yaml::class) ? YamlCassetteSerializer::class : JsonCassetteSerializer::class,
            Config::create()->serializer(),
        );
    }

    public function testAConfiguredFormatIsUsedWhateverIsInstalled(): void
    {
        self::assertInstanceOf(
            JsonCassetteSerializer::class,
            Config::create(serializer: new JsonCassetteSerializer)->serializer(),
        );
    }

    public function testConfiguringReplacesTheDefaultsForEveryClientTheProcessBuilds(): void
    {
        $clock = FrozenClock::at('2026-08-21T10:00:00+00:00');
        $persister = new InMemoryCassettePersister;

        VcrClient::configure(persister: $persister, clock: $clock, defaultMatchers: [new MethodMatcher]);

        self::assertSame($persister, Config::global()->persister());
        self::assertSame($clock, Config::global()->clock());
        self::assertEquals([new MethodMatcher], Config::global()->defaultMatchers());
    }

    public function testCarriesAllFourPsr17FactoriesIncludingTheTwoOnlyTheSymfonyBridgeUses(): void
    {
        $factory = new Psr17Factory;

        $config = Config::create(
            responseFactory: $factory,
            streamFactory: $factory,
            requestFactory: $factory,
            uriFactory: $factory,
        );

        self::assertSame([
            ResponseFactoryInterface::class => $factory,
            StreamFactoryInterface::class => $factory,
            RequestFactoryInterface::class => $factory,
            UriFactoryInterface::class => $factory,
        ], $config->psr17Factories());
    }

    public function testNamesTheProviderAHostBelongsTo(): void
    {
        $config = Config::create(providers: [
            'shopify' => new Provider(hosts: ['*.myshopify.com']),
            'zendesk' => new Provider(hosts: ['acme.zendesk.com'], requiresEnv: ['ZENDESK_API_KEY']),
        ]);

        self::assertSame('shopify', $config->providerFor('shop.myshopify.com'));
        self::assertSame('zendesk', $config->providerFor('acme.zendesk.com'));
        self::assertNull($config->providerFor('api.stripe.com'));
        self::assertSame(['ZENDESK_API_KEY'], $config->providers()['zendesk']->requiresEnv);
    }

    public function testTwoProvidersClaimingOneHostAreRefusedRatherThanSettledByOrder(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('both claim the same host');

        Config::create(providers: [
            'shopify' => new Provider(hosts: ['*.myshopify.com']),
            'one-shop' => new Provider(hosts: ['shop.myshopify.com']),
        ]);
    }

    public function testTestDirectoriesDefaultToTestsUnderTheProjectRoot(): void
    {
        self::assertSame([dirname(Config::create()->cassetteDirectory(), 2).'/tests'], Config::create()->testDirectories());
        self::assertSame(['/modules/billing/tests'], Config::create(testDirectories: ['/modules/billing/tests'])->testDirectories());
    }

    public function testTheClientToRecordThroughIsDetectedWhenTheProjectNamesNone(): void
    {
        self::assertInstanceOf(ClientInterface::class, Config::create()->innerClient());
    }

    public function testAConfiguredFactoryDecidesWhatRecordingGoesThrough(): void
    {
        $client = new FakeHttpClient;

        $config = Config::create(innerClientFactory: static fn (): ClientInterface => $client);

        self::assertSame($client, $config->innerClient());
    }

    public function testConfiguringAfterTheFirstClientExistsThrowsRatherThanQuietlyChangingDefaults(): void
    {
        new VcrClient(null, 'session', persister: new InMemoryCassettePersister);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before the first VcrClient is constructed');

        VcrClient::configure(persister: new InMemoryCassettePersister);
    }
}
