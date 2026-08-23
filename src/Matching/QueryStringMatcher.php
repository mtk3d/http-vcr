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
 */
final class QueryStringMatcher implements ExplainsMismatch, RequestMatcherInterface
{
    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
    {
        return $this->explainMismatch($recorded, $incoming) === null;
    }

    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): ?string
    {
        $expected = $this->parse($recorded->uri);
        $actual = $this->parse($incoming->uri);

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
