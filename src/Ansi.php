<?php

declare(strict_types=1);

namespace HttpVcr;

/**
 * Whether a warning printed to standard error may use color, and the three styles that
 * use it (§3.4).
 *
 * A run's warnings compete with everything else a test runner prints, and the thing worth
 * finding in that wall of text — which cassette, which value — is one short span inside a
 * line. Color is what makes that span findable at a glance; it is also actively harmful in
 * a log file, where the escape sequences are the only thing left of it. So the decision is
 * made from the environment, once per run, and every style is a no-op when it comes out
 * false.
 *
 * Deliberately hand-rolled and dependency-free: this is on the record/replay path, which
 * the §1 promise keeps clear of `symfony/console` and everything else that isn't PSR.
 */
final class Ansi
{
    private const RESET = "\033[0m";

    /**
     * Null means "not decided yet". Cached rather than re-read per warning, so a run
     * cannot print half its output colored because something changed the environment
     * halfway through.
     */
    private static ?bool $enabled = null;

    public static function enabled(): bool
    {
        return self::$enabled ??= self::detect();
    }

    /**
     * Settle the question by hand instead of detecting it — for a test asserting either
     * form of the output, and for a host that already knows (a Symfony Console command
     * running with `--no-ansi`, say). Passing null puts detection back.
     */
    public static function assume(?bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function bold(string $text): string
    {
        return self::style('1', $text);
    }

    public static function yellow(string $text): string
    {
        return self::style('33', $text);
    }

    public static function red(string $text): string
    {
        return self::style('31', $text);
    }

    /**
     * `NO_COLOR` and `FORCE_COLOR` are the cross-language convention, and both are honored
     * ahead of detection because someone who set one has already answered the question:
     * `NO_COLOR` for a log or a screen reader, `FORCE_COLOR` for CI output that renders
     * escape sequences without being a terminal — which is most hosted runners.
     *
     * Left to itself, color happens only on an actual terminal. `TERM=dumb` is a terminal
     * that says outright it cannot render it.
     */
    private static function detect(): bool
    {
        if (Environment::read('NO_COLOR') !== null) {
            return false;
        }

        $forced = Environment::read('FORCE_COLOR');

        if ($forced !== null) {
            return $forced !== '0';
        }

        if (Environment::read('TERM') === 'dumb') {
            return false;
        }

        return defined('STDERR') && stream_isatty(STDERR);
    }

    private static function style(string $code, string $text): string
    {
        return self::enabled() ? "\033[".$code.'m'.$text.self::RESET : $text;
    }
}
