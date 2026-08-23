<?php

declare(strict_types=1);

namespace HttpVcr;

/**
 * An external API with a name: the hosts it answers on, and the environment variables
 * recording traffic to it needs (§3.12).
 *
 * Naming one is an upgrade, never a prerequisite — every host in a cassette is its own
 * provider under its own name without any configuration at all. What declaring buys is
 * `requiresEnv`, which no host can imply; a name shorter and steadier than a domain
 * (`@shopify` rather than `@shop.myshopify.com`); and several hosts counted as one API.
 *
 * Which interactions belong here is worked out from the request host every time it is
 * asked, and never written into a cassette: change a pattern and it applies to everything
 * already recorded, with no stored field to drift away from this one.
 */
final readonly class Provider
{
    /**
     * @param  list<string>  $hosts  glob patterns matched against the host alone — no
     *                               scheme, port or path — case-insensitively:
     *                               `*.myshopify.com` covers any subdomain,
     *                               `account-a.zendesk.com` only that exact host
     * @param  list<string>  $requiresEnv  names checked when a request to one of these hosts
     *                                     is about to be recorded for real
     */
    public function __construct(
        public array $hosts = [],
        public array $requiresEnv = [],
    ) {}

    public function covers(string $host): bool
    {
        foreach ($this->hosts as $pattern) {
            if (preg_match(self::expression($pattern), $host) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether these two patterns can ever describe the same host — the check behind
     * refusing a configuration in which one host belongs to two providers.
     */
    public static function overlap(string $pattern, string $other): bool
    {
        return preg_match(self::expression($pattern), $other) === 1
            || preg_match(self::expression($other), $pattern) === 1;
    }

    /**
     * Translated rather than handed to fnmatch(), whose behaviour depends on the platform
     * underneath. `*` is the only wildcard, and it spans dots — `*.myshopify.com` covers a
     * host nested any number of labels deep.
     */
    private static function expression(string $pattern): string
    {
        return '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';
    }
}
