<?php

declare(strict_types=1);

namespace HttpVcr;

use HttpVcr\Exception\MissingDependencyException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Finds the PSR-17 factories the library needs (§3.14).
 *
 * `psr/http-factory` ships interfaces only, so replaying a response — building a
 * ResponseInterface and a StreamInterface out of stored values — is impossible without an
 * implementation from somewhere. Resolution goes: an explicitly supplied factory, then a
 * closed list of implementations detected with class_exists, then a MissingDependency-
 * Exception naming the one interface that is missing.
 *
 * The list is written out here rather than delegated to php-http/discovery: that would be
 * a dependency in the core to do the work of three class_exists calls, and "no ecosystem
 * dependency" is half the reason this library exists.
 */
final class Psr17FactoryResolver
{
    /**
     * Every PSR-18 client already brings one of these along, which is why detection covers
     * practically every project without any configuration. Diactoros is one class per
     * interface, so it is resolved interface by interface rather than in one hit.
     *
     * @var array<class-string, list<string>>
     */
    private const PROVIDERS = [
        ResponseFactoryInterface::class => [
            'Nyholm\Psr7\Factory\Psr17Factory',
            'GuzzleHttp\Psr7\HttpFactory',
            'Laminas\Diactoros\ResponseFactory',
        ],
        StreamFactoryInterface::class => [
            'Nyholm\Psr7\Factory\Psr17Factory',
            'GuzzleHttp\Psr7\HttpFactory',
            'Laminas\Diactoros\StreamFactory',
        ],
        RequestFactoryInterface::class => [
            'Nyholm\Psr7\Factory\Psr17Factory',
            'GuzzleHttp\Psr7\HttpFactory',
            'Laminas\Diactoros\RequestFactory',
        ],
        UriFactoryInterface::class => [
            'Nyholm\Psr7\Factory\Psr17Factory',
            'GuzzleHttp\Psr7\HttpFactory',
            'Laminas\Diactoros\UriFactory',
        ],
    ];

    /** @var array<class-string, object> */
    private array $resolved = [];

    /**
     * @param array<class-string, object|null>   $explicit   factories supplied by the caller
     * @param array<class-string, list<string>>|null $candidates the closed detection list;
     *                                                                overridable only so the
     *                                                                "nothing installed" branch
     *                                                                can be tested
     *
     * @internal the $candidates parameter
     */
    public function __construct(
        private readonly array $explicit = [],
        private readonly ?array $candidates = null,
    ) {
    }

    public function responseFactory(): ResponseFactoryInterface
    {
        return $this->resolve(ResponseFactoryInterface::class);
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->resolve(StreamFactoryInterface::class);
    }

    /**
     * Lazily: a project that never touches a bridge needing a request factory never needs
     * one to exist.
     *
     * @template T of object
     *
     * @param class-string<T> $interface
     *
     * @return T
     *
     * @throws MissingDependencyException
     */
    private function resolve(string $interface): object
    {
        $resolved = $this->resolved[$interface] ?? $this->explicit[$interface] ?? null;

        if ($resolved instanceof $interface) {
            return $this->resolved[$interface] = $resolved;
        }

        $candidates = ($this->candidates ?? self::PROVIDERS)[$interface] ?? [];

        foreach ($candidates as $candidate) {
            if (!class_exists($candidate)) {
                continue;
            }

            $factory = new $candidate();

            if ($factory instanceof $interface) {
                return $this->resolved[$interface] = $factory;
            }
        }

        throw MissingDependencyException::noImplementationOf($interface, $candidates);
    }
}
