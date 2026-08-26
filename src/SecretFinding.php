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
    /**
     * At most this much of a value is shown, however long it is.
     */
    private const LONGEST_EXCERPT = 8;

    /**
     * And at most this fraction of it, so that a short credential is not almost entirely
     * printed just because it is under the cap.
     */
    private const SHOWN = 4;

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
     *
     * A proportion rather than a fixed prefix, because the same 16 characters that reveal
     * half of a 32-character token reveal all of a 14-character one. The value is never
     * shown whole, however short: one character comes out even when a quarter rounds to
     * none, and {@see length()} is what makes an excerpt this short findable.
     */
    public function excerpt(): string
    {
        $shown = max(1, min(self::LONGEST_EXCERPT, intdiv($this->length(), self::SHOWN)));

        return substr($this->value, 0, $shown).'…';
    }

    /**
     * How long the value is — printed beside the excerpt, since a few characters and a
     * length together identify a value in a cassette that neither does alone.
     */
    public function length(): int
    {
        return strlen($this->value);
    }
}
