<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;

/**
 * Compares the host and nothing else — "any path on this API", where {@see UriMatcher} is
 * too strict, or as the host half of a pairing with a path matcher of your own.
 *
 * The host is lowercased before comparing, since a host name is case-insensitive and PSR-7
 * implementations don't agree on how to spell one.
 */
final class HostMatcher implements ExplainsMismatch, RequestMatcherInterface
{
    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
    {
        return $this->host($recorded->uri) === $this->host($incoming->uri);
    }

    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): ?string
    {
        if ($this->matches($recorded, $incoming)) {
            return null;
        }

        return sprintf('expected host "%s", got "%s"', $this->host($recorded->uri), $this->host($incoming->uri));
    }

    private function host(string $uri): string
    {
        $host = parse_url($uri, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
