<?php

declare(strict_types=1);

namespace HttpVcr;

use HttpVcr\Cassette\Interaction;
use HttpVcr\Exception\EraseTapeSelectorException;

/**
 * A parsed VCR_ERASE_TAPE value: which cassettes to re-record, and which interactions
 * inside them (§3.1).
 *
 * Forced recording deliberately isn't a RecordMode case. The three modes differ in what to
 * do when nothing matches, all of them matching against the whole cassette; this changes
 * what is left in the cassette to match against at all — the file is truncated when the
 * session opens, down to whatever the selector spares.
 */
final readonly class EraseTape
{
    /**
     * @param  list<array{cassette: string|null, provider: string|null}>  $selectors
     * @param  array<string, Provider>  $providers
     */
    private function __construct(private array $selectors, private array $providers = []) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, Provider>  $providers  the APIs this project has named
     *
     * @throws EraseTapeSelectorException when the value is a bare boolean — the shortest
     *                                    thing to type must not also be the widest blast
     *                                    radius, so `all` has to be said out loud — or when
     *                                    a `@name` could not have meant anything
     */
    public static function parse(?string $value, array $providers = []): self
    {
        if ($value === null || trim($value) === '') {
            return self::none();
        }

        $selectors = [];

        foreach (explode(',', $value) as $selector) {
            $selector = trim($selector);

            if ($selector === '') {
                continue;
            }

            if (in_array(strtolower($selector), ['0', '1', 'true', 'false'], true)) {
                throw EraseTapeSelectorException::bareBoolean($selector);
            }

            $at = strpos($selector, '@');
            $cassette = $at === false ? $selector : substr($selector, 0, $at);
            $provider = $at === false ? null : substr($selector, $at + 1);

            if ($provider === '') {
                throw EraseTapeSelectorException::noApiAfterAt($selector);
            }

            if ($provider !== null && ! isset($providers[$provider]) && ! self::couldBeAHost($provider)) {
                throw EraseTapeSelectorException::unknownProvider($provider, array_keys($providers));
            }

            $selectors[] = [
                'cassette' => $cassette === '' || $cassette === 'all' ? null : $cassette,
                'provider' => $provider,
            ];
        }

        return new self($selectors, $providers);
    }

    /**
     * Whether a name that isn't a configured provider can still have been meant as a host.
     *
     * A dot settles it, since every public hostname has one and no provider name needs one.
     * `localhost` is the one dotless host common enough to name — anything else without a
     * dot is a name that resolves to nothing, which is the typo this is here to catch.
     */
    private static function couldBeAHost(string $name): bool
    {
        return str_contains($name, '.') || strcasecmp($name, 'localhost') === 0;
    }

    public function isActive(): bool
    {
        return $this->selectors !== [];
    }

    /**
     * Whether this cassette is re-recorded at all. Matched on the base name, so a scoped
     * cassette is caught whichever scope's file is actually open.
     */
    public function covers(string $cassetteName): bool
    {
        return $this->selectorsFor($cassetteName) !== [];
    }

    /**
     * Whether an interaction survives the truncation of a covered cassette.
     *
     * Locked interactions always do — that is the whole point of the lock, and it outranks
     * every environment variable. So does traffic to any API other than the one a
     * `@provider` selector narrowed to.
     */
    public function spares(string $cassetteName, Interaction $interaction): bool
    {
        if ($interaction->locked) {
            return true;
        }

        $host = parse_url($interaction->request->uri, PHP_URL_HOST);

        return ! $this->erases($cassetteName, is_string($host) ? $host : null);
    }

    /**
     * Whether the tape erases — and so re-records — traffic to $host in this cassette.
     *
     * The question `spares()` asks about an interaction already on disk, asked about a
     * request about to happen: a `@provider` selector re-records that API and leaves the
     * rest of the cassette to the mode the session declared (§7 decision 76). A selector
     * with no `@` half names the whole cassette, so every host in it is covered.
     */
    public function erases(string $cassetteName, ?string $host): bool
    {
        foreach ($this->selectorsFor($cassetteName) as $selector) {
            if ($selector['provider'] === null) {
                return true;
            }

            if ($host !== null && $this->belongsTo($host, $selector['provider'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{cassette: string|null, provider: string|null}>
     */
    private function selectorsFor(string $cassetteName): array
    {
        return array_values(array_filter(
            $this->selectors,
            static fn (array $selector): bool => $selector['cassette'] === null
                || $selector['cassette'] === $cassetteName,
        ));
    }

    /**
     * Every host is its own API until a project names one, so this needs no configuration.
     *
     * A named provider is matched by its host patterns; anything else is taken as a host
     * and compared exactly, since a glob is a judgement about what counts as one API rather
     * than something readable out of the data. A host a named provider has claimed stops
     * answering to its own name, so one thing always has exactly one selector.
     */
    private function belongsTo(string $host, string $provider): bool
    {
        $named = $this->providers[$provider] ?? null;

        if ($named !== null) {
            return $named->covers($host);
        }

        return strcasecmp($host, $provider) === 0 && $this->claimant($host) === null;
    }

    /**
     * The named provider this host belongs to, if any.
     */
    private function claimant(string $host): ?Provider
    {
        foreach ($this->providers as $provider) {
            if ($provider->covers($host)) {
                return $provider;
            }
        }

        return null;
    }
}
