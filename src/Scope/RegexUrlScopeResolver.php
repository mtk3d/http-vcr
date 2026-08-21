<?php

declare(strict_types=1);

namespace HttpVcr\Scope;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * Reads the scope out of the request URI with a regular expression, from a group named
 * `scope`:
 *
 * ```php
 * new RegexUrlScopeResolver('#/api/(?<scope>\d{4}-\d{2})/#');  // Shopify: a date
 * new RegexUrlScopeResolver('#/v(?<scope>\d+)/#');             // a version number
 * ```
 *
 * A URI the pattern doesn't match is unscoped, and lands in the cassette's own file — which
 * is what makes a resolver safe to apply to a cassette holding traffic to more than one
 * API.
 */
final class RegexUrlScopeResolver implements CassetteScopeResolverInterface
{
    /**
     * @param string $pattern matched against the full URI, so it can key on the host as
     *                        readily as on the path
     */
    public function __construct(private readonly string $pattern)
    {
        // Checked where it is written rather than at the first request: a typo in a regex
        // has no business surfacing as a cassette that suddenly matches nothing.
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid regular expression.', $pattern));
        }
    }

    public function resolve(RequestInterface $request): ?string
    {
        if (preg_match($this->pattern, (string) $request->getUri(), $matches) !== 1) {
            return null;
        }

        if (!isset($matches['scope']) || !is_string($matches['scope'])) {
            throw new InvalidArgumentException(sprintf(
                'The pattern "%s" matched %s but has no group named "scope", so there is nothing to '
                . 'name the cassette file with. Write it as (?<scope>...).',
                $this->pattern,
                (string) $request->getUri(),
            ));
        }

        return $matches['scope'];
    }
}
