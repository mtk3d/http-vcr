<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use HttpVcr\Clock\FrozenClock;
use HttpVcr\Config;
use HttpVcr\Matching\MethodMatcher;
use HttpVcr\Matching\QueryStringMatcher;
use HttpVcr\Matching\UriMatcher;
use HttpVcr\Persistence\FilesystemCassettePersister;
use HttpVcr\Serializer\JsonCassetteSerializer;
use HttpVcr\Tests\Support\InMemoryCassettePersister;
use HttpVcr\VcrClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

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
        self::assertFileExists(dirname(Config::create()->cassetteDirectory(), 2) . '/composer.json');
    }

    public function testTheDefaultsAreJsonOnTheFilesystemMatchedOnMethodUriAndQueryString(): void
    {
        $config = Config::create();

        self::assertInstanceOf(JsonCassetteSerializer::class, $config->serializer());
        self::assertInstanceOf(FilesystemCassettePersister::class, $config->persister());
        self::assertEquals(
            [new MethodMatcher(), new UriMatcher(), new QueryStringMatcher()],
            $config->defaultMatchers(),
        );
    }

    public function testConfiguringReplacesTheDefaultsForEveryClientTheProcessBuilds(): void
    {
        $clock = FrozenClock::at('2026-08-21T10:00:00+00:00');
        $persister = new InMemoryCassettePersister();

        VcrClient::configure(persister: $persister, clock: $clock, defaultMatchers: [new MethodMatcher()]);

        self::assertSame($persister, Config::global()->persister());
        self::assertSame($clock, Config::global()->clock());
        self::assertEquals([new MethodMatcher()], Config::global()->defaultMatchers());
    }

    public function testCarriesAllFourPsr17FactoriesIncludingTheTwoOnlyTheSymfonyBridgeUses(): void
    {
        $factory = new Psr17Factory();

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

    public function testConfiguringAfterTheFirstClientExistsThrowsRatherThanQuietlyChangingDefaults(): void
    {
        new VcrClient(null, 'session', persister: new InMemoryCassettePersister());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before the first VcrClient is constructed');

        VcrClient::configure(persister: new InMemoryCassettePersister());
    }
}
