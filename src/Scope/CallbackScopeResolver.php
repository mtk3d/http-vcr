<?php

declare(strict_types=1);

namespace HttpVcr\Scope;

use Closure;
use Psr\Http\Message\RequestInterface;

/**
 * Any logic at all — for an API that versions itself somewhere other than the URL, an
 * `Accept: application/vnd.api+json;version=3` header being the usual example.
 */
final class CallbackScopeResolver implements CassetteScopeResolverInterface
{
    /** @var Closure(RequestInterface): ?string */
    private readonly Closure $resolve;

    /**
     * @param callable(RequestInterface): ?string $resolve null for a request that isn't scoped
     */
    public function __construct(callable $resolve)
    {
        $this->resolve = $resolve(...);
    }

    public function resolve(RequestInterface $request): ?string
    {
        return ($this->resolve)($request);
    }
}
