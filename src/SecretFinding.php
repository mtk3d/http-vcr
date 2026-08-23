<?php

declare(strict_types=1);

namespace HttpVcr;

/**
 * One credential-shaped value a scan found in a cassette, and where it sits.
 *
 * A finding, not a fault: the library recorded exactly what it was asked to. Whether
 * `sk_live_…` in a payment test is a leak or a fixture describing an error response is a
 * judgement that needs context the library doesn't have.
 */
final readonly class SecretFinding
{
    private const EXCERPT_LENGTH = 16;

    /**
     * @param  string  $location  where the value sits, as `response.body (/refresh_token)`
     * @param  string  $value  the value itself, never printed in full
     */
    public function __construct(
        public string $location,
        public string $value,
    ) {}

    /**
     * Enough of the value to recognize it in the cassette, and no more — a warning about a
     * secret has no business printing the secret into a terminal or a CI log.
     */
    public function excerpt(): string
    {
        return strlen($this->value) > self::EXCERPT_LENGTH
            ? substr($this->value, 0, self::EXCERPT_LENGTH).'…'
            : $this->value;
    }
}
