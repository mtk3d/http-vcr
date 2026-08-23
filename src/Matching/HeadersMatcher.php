<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\Headers;
use HttpVcr\Cassette\RecordedRequest;

/**
 * Compares request headers, by default as a subset: every header the cassette recorded
 * has to be present in the incoming request with the same value, and headers the incoming
 * request carries beyond that don't fail the match.
 *
 * That default is not laziness. Guzzle and Symfony each add headers of their own —
 * `User-Agent`, `Accept-Encoding` — that no application code asked for, so a 1:1
 * comparison would break every cassette in a project the day it swapped HTTP client
 * libraries, unless every one of those headers had been listed as an exception by hand.
 * `exact: true` opts into that comparison for a test that specifically verifies the whole
 * header set.
 *
 * Names are folded to lowercase before comparing: PSR-7 treats them as case-insensitive,
 * but a cassette keeps the capitalization an API used and a local client picks its own.
 */
final class HeadersMatcher implements ExplainsMismatch, RequestMatcherInterface
{
    /** @var list<string> */
    private readonly array $headers;

    /**
     * @param  list<string>  $headers  the header names to compare; empty means every header
     *                                 the recorded request carries
     * @param  bool  $exact  require the same set of headers on both sides, rather
     *                       than the recorded ones being present among the incoming
     */
    public function __construct(array $headers = [], private readonly bool $exact = false)
    {
        $this->headers = array_values(array_map(strtolower(...), $headers));
    }

    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
    {
        return $this->explainMismatch($recorded, $incoming) === null;
    }

    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): ?string
    {
        $expected = Headers::lowercased($recorded->headers);
        $actual = Headers::lowercased($incoming->headers);

        foreach ($this->names($expected, $actual) as $name) {
            $mismatch = $this->compare($name, $expected[$name] ?? null, $actual[$name] ?? null);

            if ($mismatch !== null) {
                return $mismatch;
            }
        }

        return null;
    }

    /**
     * @param  list<string>|null  $expected
     * @param  list<string>|null  $actual
     */
    private function compare(string $name, ?array $expected, ?array $actual): ?string
    {
        // Nothing was recorded under this name, so in subset mode there is nothing to
        // require; in exact mode carrying it anyway is the mismatch.
        if ($expected === null) {
            return $actual === null || ! $this->exact ? null : sprintf('unexpected header "%s"', $name);
        }

        if ($actual === null) {
            return sprintf('header "%s" missing', $name);
        }

        if ($expected !== $actual) {
            return sprintf(
                'header "%s" expected %s, got %s',
                $name,
                $this->describe($expected),
                $this->describe($actual),
            );
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $expected
     * @param  array<string, list<string>>  $actual
     * @return list<string> the header names this matcher has an opinion about
     */
    private function names(array $expected, array $actual): array
    {
        if ($this->headers !== []) {
            return $this->headers;
        }

        $names = array_keys($expected);

        if ($this->exact) {
            $names = array_merge($names, array_diff(array_keys($actual), $names));
        }

        return array_values($names);
    }

    /**
     * @param  list<string>  $values
     */
    private function describe(array $values): string
    {
        $quoted = array_map(static fn (string $value): string => '"'.$value.'"', $values);

        return count($quoted) === 1 ? $quoted[0] : '['.implode(', ', $quoted).']';
    }
}
