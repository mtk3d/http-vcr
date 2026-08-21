<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

/**
 * Case-insensitive operations on the `array<string, list<string>>` header shape both
 * snapshots store. PSR-7 treats header names as case-insensitive; a cassette keeps them
 * spelled the way they were sent, so lookups have to fold case rather than the data.
 *
 * @internal
 */
final class Headers
{
    /**
     * @param array<string, list<string>> $headers
     *
     * @return list<string>
     */
    public static function get(array $headers, string $name): array
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values;
            }
        }

        return [];
    }

    /**
     * @param array<string, list<string>> $headers
     * @param string|list<string>         $value
     *
     * @return array<string, list<string>>
     */
    public static function with(array $headers, string $name, string|array $value): array
    {
        $headers = self::without($headers, $name);
        $headers[$name] = is_string($value) ? [$value] : array_values($value);

        return $headers;
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    public static function without(array $headers, string $name): array
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                unset($headers[$key]);
            }
        }

        return $headers;
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>> the same headers with every name lowercased,
     *                                     values of names differing only in case merged
     */
    public static function lowercased(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $key = strtolower($name);
            $normalized[$key] = array_merge($normalized[$key] ?? [], $values);
        }

        return $normalized;
    }
}
