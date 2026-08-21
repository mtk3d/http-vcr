<?php

declare(strict_types=1);

namespace HttpVcr;

use stdClass;

/**
 * RFC 6901 JSON Pointers over a decoded JSON document — the addressing scheme both
 * {@see Matching\BodyJsonMatcher} and {@see Hook\RedactionHooks} name a field with.
 *
 * Documents are the shape `json_decode()` produces without `associative`: objects as
 * stdClass, arrays as lists. That distinction is the reason for it — with associative
 * arrays, `{}` and `[]` are the same value, and a matcher would call two different
 * documents equal.
 *
 * @internal
 */
final class JsonPointer
{
    /**
     * @return list<string> the pointer's reference tokens, unescaped
     */
    public static function tokens(string $pointer): array
    {
        $tokens = explode('/', $pointer);

        if ($tokens[0] === '') {
            array_shift($tokens);
        }

        return array_values(array_map(
            static fn (string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token),
            $tokens,
        ));
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{mixed}|null the value wrapped in a one-element list, or null when the
     *                           document has no member there — so that a member holding
     *                           null stays distinguishable from an absent one
     */
    public static function read(mixed $document, array $tokens): ?array
    {
        foreach ($tokens as $token) {
            if ($document instanceof stdClass && property_exists($document, $token)) {
                $document = $document->{$token};

                continue;
            }

            if (is_array($document) && ctype_digit($token) && array_key_exists((int) $token, $document)) {
                $document = $document[(int) $token];

                continue;
            }

            return null;
        }

        return [$document];
    }

    /**
     * The document with a different value at $tokens — a new one, and unchanged when the
     * member isn't there: neither a matcher nor a redaction rule has any business inventing
     * a field the traffic didn't carry.
     *
     * @param list<string> $tokens
     */
    public static function with(mixed $document, array $tokens, mixed $value): mixed
    {
        return self::replace($document, $tokens, [$value]);
    }

    /**
     * The document without the member at $tokens, or unchanged when it isn't there.
     *
     * @param list<string> $tokens
     */
    public static function without(mixed $document, array $tokens): mixed
    {
        return self::replace($document, $tokens, null);
    }

    /**
     * @param list<string>  $tokens
     * @param array{mixed}|null $value the replacement wrapped in a one-element list, or
     *                                 null to remove the member
     */
    private static function replace(mixed $document, array $tokens, ?array $value): mixed
    {
        $token = array_shift($tokens);

        if ($token === null) {
            return $value === null ? $document : $value[0];
        }

        if ($document instanceof stdClass && property_exists($document, $token)) {
            $document = clone $document;

            if ($tokens !== []) {
                $document->{$token} = self::replace($document->{$token}, $tokens, $value);
            } elseif ($value === null) {
                unset($document->{$token});
            } else {
                $document->{$token} = $value[0];
            }

            return $document;
        }

        if (is_array($document) && ctype_digit($token) && array_key_exists((int) $token, $document)) {
            $index = (int) $token;

            if ($tokens !== []) {
                $document[$index] = self::replace($document[$index], $tokens, $value);
            } elseif ($value === null) {
                unset($document[$index]);

                return array_values($document);
            } else {
                $document[$index] = $value[0];
            }
        }

        return $document;
    }
}
