<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use RuntimeException;

/**
 * The environment isn't complete: nothing installed to rebuild responses with, or nothing
 * to make a real request through.
 *
 * Names the one interface that is missing, rather than "PSR-17 factories" collectively —
 * a project that already ships three of the four shouldn't be told to install what it has.
 */
final class MissingDependencyException extends RuntimeException implements VcrException
{
    /**
     * @param class-string $interface
     * @param list<string> $candidates the implementations that were looked for
     */
    public static function noImplementationOf(string $interface, array $candidates): self
    {
        return new self(sprintf(
            'No implementation of %s found. Install one (composer require --dev nyholm/psr7) '
            . "or pass your own to VcrClient.\nLooked for: %s.",
            $interface,
            implode(', ', $candidates),
        ));
    }

    public static function noYaml(): self
    {
        return new self(
            'The YAML cassette serializer needs symfony/yaml (composer require --dev symfony/yaml). '
            . 'The default JsonCassetteSerializer needs nothing installed.',
        );
    }

    /**
     * @param list<string> $candidates the clients that were looked for
     */
    public static function noHttpClient(array $candidates): self
    {
        return new self(sprintf(
            'No HTTP client found to record through. Install one (composer require --dev guzzlehttp/guzzle) '
            . "or configure innerClientFactory in http-vcr.php.\nLooked for: %s.",
            implode(', ', $candidates),
        ));
    }
}
