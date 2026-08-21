<?php

declare(strict_types=1);

namespace HttpVcr\Scope;

use Psr\Http\Message\RequestInterface;

/**
 * Splits one cassette name across several files, by something read off the request —
 * typically the API version in the URL (§3.8).
 *
 * The point is a readable failure when application code moves to a new API version: the
 * scope changes, no file exists for it, and http-vcr says exactly that instead of turning
 * up a stale interaction recorded against the version before.
 */
interface CassetteScopeResolverInterface
{
    /**
     * @return string|null the key appended to the cassette file name; null means this
     *                     request isn't scoped and belongs in the cassette's own file
     */
    public function resolve(RequestInterface $request): ?string;
}
