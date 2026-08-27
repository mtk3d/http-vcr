<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;

/**
 * Compares query parameters as an unordered set — `?a=1&b=2` and `?b=2&a=1` are the same
 * request — while keeping repeated keys meaningful: `?tag=a&tag=b` is a list, and the
 * order within that list counts.
 *
 * In the default matcher set, so `?page=1` and `?page=2` never quietly replay each
 * other's recording.
 *
 * A parameter that legitimately changes every run — a timestamp, a nonce, a signature
 * computed over one of those — is what {@see self::ignoreQueryParam()} and {@see
 * self::matchOnlyQueryParams()} are for. Redaction can't help there: it substitutes a value
 * known in advance, and these aren't.
 */
final class QueryStringMatcher implements ExplainsMismatch, RequestMatcherInterface
{
    /** @var list<string> */
    private array $ignored = [];

    /** @var list<string> */
    private array $only = [];

    /**
     * Excludes $name from the comparison entirely: any value on either side, or none at
     * all, counts as equal.
     *
     * Returns a new matcher rather than configuring this one, so a matcher stays a value
     * that can be built in a single expression inside the `matchers:` array.
     */
    public function ignoreQueryParam(string $name): self
    {
        $copy = clone $this;
        $copy->ignored[] = $name;

        return $copy;
    }

    /**
     * Compares these parameters and nothing else — the inverse of listing what to ignore,
     * for a URL where the few parameters that identify the request are outnumbered by the
     * ones that don't.
     *
     * The named ones are still compared in full: one missing on the incoming side is a
     * mismatch, the same as it would be without this.
     *
     * @param  list<string>  $names
     */
    public function matchOnlyQueryParams(array $names): self
    {
        $copy = clone $this;
        $copy->only = array_values($names);

        return $copy;
    }

    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
    {
        return $this->explainMismatch($recorded, $incoming) === null;
    }

    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): ?string
    {
        $expected = $this->compared($this->parse($recorded->uri));
        $actual = $this->compared($this->parse($incoming->uri));

        foreach ($expected as $name => $values) {
            if (! array_key_exists($name, $actual)) {
                return sprintf('parameter "%s" missing', $name);
            }

            if ($values !== $actual[$name]) {
                return sprintf(
                    'parameter "%s" expected %s, got %s',
                    $name,
                    $this->describe($values),
                    $this->describe($actual[$name]),
                );
            }
        }

        foreach ($actual as $name => $values) {
            if (! array_key_exists($name, $expected)) {
                return sprintf('unexpected parameter "%s"', $name);
            }
        }

        return null;
    }

    /**
     * What is left of a query once the configuration has had its say. Ignoring wins over
     * naming: a parameter in both lists was named twice by someone who meant to exclude it.
     *
     * @param  array<string, list<string|null>>  $parameters
     * @return array<string, list<string|null>>
     */
    private function compared(array $parameters): array
    {
        if ($this->ignored === [] && $this->only === []) {
            return $parameters;
        }

        return array_filter(
            $parameters,
            fn (string $name): bool => ! in_array($name, $this->ignored, true)
                && ($this->only === [] || in_array($name, $this->only, true)),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string, list<string|null>> parameter name to its values, in order;
     *                                          null is a parameter given without a value
     */
    private function parse(string $uri): array
    {
        $query = parse_url($uri, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [];
        }

        $parameters = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $separator = strpos($pair, '=');
            $name = urldecode($separator === false ? $pair : substr($pair, 0, $separator));
            $parameters[$name][] = $separator === false ? null : urldecode(substr($pair, $separator + 1));
        }

        return $parameters;
    }

    /**
     * @param  list<string|null>  $values
     */
    private function describe(array $values): string
    {
        $quoted = array_map(
            static fn (?string $value): string => $value === null ? '(no value)' : '"'.$value.'"',
            $values,
        );

        return count($quoted) === 1 ? $quoted[0] : '['.implode(', ', $quoted).']';
    }
}
