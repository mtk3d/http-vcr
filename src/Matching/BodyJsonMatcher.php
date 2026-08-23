<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\JsonPointer;
use InvalidArgumentException;
use stdClass;

/**
 * Compares request bodies as JSON documents rather than as strings: `{"a":1,"b":2}` and
 * `{"b":2,"a":1}` are the same request, formatting and key order are not a difference.
 *
 * Scalars are compared strictly — `100` is not `"100"` — and array order is significant,
 * because a list in a request body usually means one. When either body isn't valid JSON
 * the comparison falls back to the raw one {@see BodyMatcher} does.
 *
 * Values that legitimately change every run (a generated UUID, a client-side timestamp)
 * are what {@see self::ignoreJsonField()} and {@see self::matchJsonField()} are for.
 * Redaction can't help there: it substitutes a value known in advance, and these aren't.
 */
final class BodyJsonMatcher implements ExplainsMismatch, RequestMatcherInterface
{
    /** @var list<string> */
    private array $ignored = [];

    /** @var array<string, string> */
    private array $patterns = [];

    /**
     * Excludes the field at $pointer (a JSON Pointer) from the comparison entirely: any
     * value on either side, or none at all, counts as equal.
     *
     * Returns a new matcher rather than configuring this one, so a matcher stays a value
     * that can be built in a single expression inside the `matchers:` array.
     */
    public function ignoreJsonField(string $pointer): self
    {
        $copy = clone $this;
        $copy->ignored[] = $pointer;

        return $copy;
    }

    /**
     * Requires the field at $pointer to *look* like something — to match $pattern — rather
     * than to be identical on both sides. The middle ground between comparing a generated
     * value and ignoring it.
     */
    public function matchJsonField(string $pointer, string $pattern): self
    {
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException(sprintf(
                'matchJsonField("%s", "%s"): the pattern is not a usable regular expression. '
                .'It needs delimiters, as preg_match takes them — "/^[0-9a-f-]{36}$/".',
                $pointer,
                $pattern,
            ));
        }

        $copy = clone $this;
        $copy->patterns[$pointer] = $pattern;

        return $copy;
    }

    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
    {
        return $this->explainMismatch($recorded, $incoming) === null;
    }

    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): ?string
    {
        $expected = $this->decode($recorded->body);
        $actual = $this->decode($incoming->body);

        if ($expected === null || $actual === null) {
            $detail = (new BodyMatcher)->explainMismatch($recorded, $incoming);

            return $detail === null ? null : 'raw body: '.$detail;
        }

        [$expectedDocument, $actualDocument] = [$expected[0], $actual[0]];

        foreach ($this->patterns as $pointer => $pattern) {
            $mismatch = $this->checkPattern($expectedDocument, $actualDocument, $pointer, $pattern);

            if ($mismatch !== null) {
                return $mismatch;
            }

            $expectedDocument = JsonPointer::without($expectedDocument, JsonPointer::tokens($pointer));
            $actualDocument = JsonPointer::without($actualDocument, JsonPointer::tokens($pointer));
        }

        foreach ($this->ignored as $pointer) {
            $expectedDocument = JsonPointer::without($expectedDocument, JsonPointer::tokens($pointer));
            $actualDocument = JsonPointer::without($actualDocument, JsonPointer::tokens($pointer));
        }

        return $this->compare($expectedDocument, $actualDocument, '');
    }

    private function checkPattern(mixed $expected, mixed $actual, string $pointer, string $pattern): ?string
    {
        $tokens = JsonPointer::tokens($pointer);
        $path = implode('/', $tokens);
        $recorded = JsonPointer::read($expected, $tokens);
        $incoming = JsonPointer::read($actual, $tokens);

        if ($incoming === null) {
            return sprintf('field "%s" missing', $path);
        }

        if ($recorded === null) {
            return sprintf('unexpected field "%s"', $path);
        }

        $value = $incoming[0];
        $subject = match (true) {
            is_string($value) => $value,
            is_scalar($value) => $this->describe($value),
            default => null,
        };

        if ($subject === null || preg_match($pattern, $subject) !== 1) {
            return sprintf('field "%s" expected to match %s, got %s', $path, $pattern, $this->describe($value));
        }

        return null;
    }

    private function compare(mixed $expected, mixed $actual, string $path): ?string
    {
        if ($expected instanceof stdClass && $actual instanceof stdClass) {
            return $this->compareObjects($expected, $actual, $path);
        }

        if (is_array($expected) && is_array($actual)) {
            return $this->compareArrays($expected, $actual, $path);
        }

        if ($expected === $actual) {
            return null;
        }

        return $this->at($path).sprintf('expected %s, got %s', $this->describe($expected), $this->describe($actual));
    }

    private function compareObjects(stdClass $expected, stdClass $actual, string $path): ?string
    {
        foreach (get_object_vars($expected) as $key => $value) {
            if (! property_exists($actual, $key)) {
                return sprintf('field "%s" missing', $this->join($path, $key));
            }

            $mismatch = $this->compare($value, $actual->{$key}, $this->join($path, $key));

            if ($mismatch !== null) {
                return $mismatch;
            }
        }

        foreach (array_keys(get_object_vars($actual)) as $key) {
            if (! property_exists($expected, (string) $key)) {
                return sprintf('unexpected field "%s"', $this->join($path, (string) $key));
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $expected
     * @param  array<int|string, mixed>  $actual
     */
    private function compareArrays(array $expected, array $actual, string $path): ?string
    {
        if (count($expected) !== count($actual)) {
            return $this->at($path).sprintf(
                'expected %d element%s, got %d',
                count($expected),
                count($expected) === 1 ? '' : 's',
                count($actual),
            );
        }

        foreach ($expected as $index => $value) {
            $mismatch = $this->compare($value, $actual[$index], $this->join($path, (string) $index));

            if ($mismatch !== null) {
                return $mismatch;
            }
        }

        return null;
    }

    /**
     * @return array{mixed}|null a one-element list holding the document, so that a body of
     *                           `null` stays distinguishable from a body that isn't JSON
     */
    private function decode(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body);

        return json_last_error() === JSON_ERROR_NONE ? [$decoded] : null;
    }

    private function join(string $path, string $key): string
    {
        return $path === '' ? $key : $path.'/'.$key;
    }

    private function at(string $path): string
    {
        return $path === '' ? '' : sprintf('field "%s" ', $path);
    }

    private function describe(mixed $value): string
    {
        if ($value instanceof stdClass) {
            return 'an object';
        }

        if (is_array($value)) {
            return 'an array';
        }

        $encoded = json_encode(
            is_string($value) ? Excerpt::of($value) : $value,
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $encoded === false ? 'an unencodable value' : $encoded;
    }
}
