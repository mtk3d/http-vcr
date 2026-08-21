<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;

/**
 * Compares scheme, host and path — the query string has its own {@see QueryStringMatcher},
 * because it is routinely matched on different terms (ignoring pagination, say).
 *
 * PSR-7 implementations differ in how they spell the same URL, so scheme and host are
 * lowercased and a port that is the scheme's default is dropped before comparing. Percent
 * encoding is left exactly as it is: a different encoding can mean different bytes.
 * A trailing slash is significant — `/users/` and `/users` are different resources in
 * plenty of APIs — except on the root path, where an empty path and `/` are the same.
 */
final class UriMatcher implements RequestMatcherInterface, ExplainsMismatch
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
    {
        return $this->explainMismatch($recorded, $incoming) === null;
    }

    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): ?string
    {
        $expected = $this->normalize($recorded->uri);
        $actual = $this->normalize($incoming->uri);

        foreach (['scheme', 'host', 'port', 'path'] as $component) {
            if ($expected[$component] !== $actual[$component]) {
                return sprintf('expected %s "%s"', $component, (string) $expected[$component]);
            }
        }

        return null;
    }

    /**
     * @return array{scheme: string, host: string, port: int|null, path: string}
     */
    private function normalize(string $uri): array
    {
        $parts = parse_url($uri);
        $parts = $parts === false ? [] : $parts;

        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';

        if ($port !== null && (self::DEFAULT_PORTS[$scheme] ?? null) === $port) {
            $port = null;
        }

        return [
            'scheme' => $scheme,
            'host' => strtolower($parts['host'] ?? ''),
            'port' => $port,
            'path' => $path === '' ? '/' : $path,
        ];
    }
}
