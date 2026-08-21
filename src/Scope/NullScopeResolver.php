<?php

declare(strict_types=1);

namespace HttpVcr\Scope;

use Psr\Http\Message\RequestInterface;

/**
 * The default: one cassette name, one file.
 */
final class NullScopeResolver implements CassetteScopeResolverInterface
{
    public function resolve(RequestInterface $request): ?string
    {
        return null;
    }
}
