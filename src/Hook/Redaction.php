<?php

declare(strict_types=1);

namespace HttpVcr\Hook;

use Closure;

/**
 * One redaction rule: what to look for, and what to put in its place.
 *
 * A rule with a value provider is two-way — the real value can be put back when the
 * cassette is replayed. Without one it is write-only: the placeholder goes to disk and
 * nothing can turn it back, which is why such a field stops distinguishing interactions
 * for matching (§3.3).
 *
 * @internal
 */
final readonly class Redaction
{
    private function __construct(
        public RedactionTarget $target,
        public string $name,
        public string $placeholder,
        private ?Closure $provider,
    ) {
    }

    public static function of(
        RedactionTarget $target,
        string $name,
        string $placeholder,
        ?callable $provider = null,
    ): self {
        return new self($target, $name, $placeholder, $provider === null ? null : $provider(...));
    }

    public function isTwoWay(): bool
    {
        return $this->provider !== null;
    }

    /**
     * The real value behind the placeholder, asked for at the moment it is needed rather
     * than when the rule was declared — an environment variable read at configuration time
     * would be read before a test had the chance to set it.
     */
    public function value(): ?string
    {
        if ($this->provider === null) {
            return null;
        }

        $value = ($this->provider)();

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * The placeholder a rule generates when only given a field name: `<REDACTED-API-KEY>`
     * for `api_key`, `<REDACTED-CUSTOMER-EMAIL>` for `/customer/email`. Fixed rather than
     * random, so a cassette diff stays readable and a secret scanner can recognize it by
     * its shape.
     */
    public static function placeholderFor(string $name): string
    {
        $upper = strtoupper($name);
        $dashed = trim((string) preg_replace(['/[^A-Z0-9]+/', '/-+/'], '-', $upper), '-');

        return '<REDACTED-' . $dashed . '>';
    }
}
