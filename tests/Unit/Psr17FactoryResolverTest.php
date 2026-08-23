<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use HttpVcr\Exception\MissingDependencyException;
use HttpVcr\Psr17FactoryResolver;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\UriFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

#[CoversClass(Psr17FactoryResolver::class)]
#[CoversClass(MissingDependencyException::class)]
final class Psr17FactoryResolverTest extends TestCase
{
    public function testDetectsAnInstalledImplementation(): void
    {
        $resolver = new Psr17FactoryResolver;

        self::assertInstanceOf(ResponseFactoryInterface::class, $resolver->responseFactory());
        self::assertInstanceOf(StreamFactoryInterface::class, $resolver->streamFactory());
    }

    public function testResolvesTheTwoFactoriesOnlyTheSymfonyBridgeNeeds(): void
    {
        $resolver = new Psr17FactoryResolver;

        self::assertInstanceOf(RequestFactoryInterface::class, $resolver->requestFactory());
        self::assertInstanceOf(UriFactoryInterface::class, $resolver->uriFactory());
    }

    public function testAProviderSplitAcrossClassesCoversTheRequestAndUriFactoriesToo(): void
    {
        $resolver = new Psr17FactoryResolver([], [
            RequestFactoryInterface::class => ['Laminas\Diactoros\RequestFactory'],
            UriFactoryInterface::class => ['Laminas\Diactoros\UriFactory'],
        ]);

        self::assertInstanceOf(RequestFactory::class, $resolver->requestFactory());
        self::assertInstanceOf(UriFactory::class, $resolver->uriFactory());
    }

    public function testAnExplicitFactoryWinsOverDetection(): void
    {
        $factory = new ResponseFactory;

        $resolver = new Psr17FactoryResolver([ResponseFactoryInterface::class => $factory]);

        self::assertSame($factory, $resolver->responseFactory());
    }

    public function testTheSameInstanceIsReusedOnceResolved(): void
    {
        $resolver = new Psr17FactoryResolver;

        self::assertSame($resolver->responseFactory(), $resolver->responseFactory());
    }

    public function testAProviderSplitAcrossClassesIsResolvedInterfaceByInterface(): void
    {
        $resolver = new Psr17FactoryResolver([], [
            ResponseFactoryInterface::class => ['Laminas\Diactoros\ResponseFactory'],
            StreamFactoryInterface::class => ['Laminas\Diactoros\StreamFactory'],
        ]);

        self::assertInstanceOf(ResponseFactory::class, $resolver->responseFactory());
        self::assertInstanceOf(StreamFactoryInterface::class, $resolver->streamFactory());
    }

    public function testWithNothingInstalledItNamesTheOneInterfaceThatIsMissing(): void
    {
        $resolver = new Psr17FactoryResolver([], [
            ResponseFactoryInterface::class => ['Vendor\NotInstalled\Psr17Factory'],
        ]);

        $this->expectException(MissingDependencyException::class);
        $this->expectExceptionMessage('No implementation of Psr\Http\Message\ResponseFactoryInterface found');
        $this->expectExceptionMessage('Vendor\NotInstalled\Psr17Factory');

        $resolver->responseFactory();
    }

    public function testAnExplicitFactoryCoversAnInterfaceNothingElseProvides(): void
    {
        $resolver = new Psr17FactoryResolver(
            [StreamFactoryInterface::class => new Psr17Factory],
            [StreamFactoryInterface::class => []],
        );

        self::assertInstanceOf(Psr17Factory::class, $resolver->streamFactory());
    }
}
