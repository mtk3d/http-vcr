<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use HttpVcr\VcrClient;

/**
 * The cassette session the test running right now is using.
 *
 * A public, BC-guaranteed contract rather than an internal detail of this bridge: the
 * separate Laravel package reads it at request time to decide whether a call through the
 * global `Http` facade belongs to an active session (§3.13). Anything integrating a
 * framework whose HTTP entry point is global needs the same seam.
 *
 * Process-level by necessity — a test method has no object the bridge could hand this to —
 * and emptied after every test, so nothing survives into the next one.
 */
final class CurrentCassetteSession
{
    private static ?VcrClient $client = null;

    /**
     * Whether a cassette is open right now, which for a framework hook is the question of
     * whether this request should be recorded and replayed at all.
     */
    public static function isActive(): bool
    {
        return self::$client !== null;
    }

    /**
     * The PSR-18 client for the open cassette, or null when there is none — a test with no
     * `#[UseCassette]`, or a run where the extension was never registered.
     */
    public static function client(): ?VcrClient
    {
        return self::$client;
    }

    public static function begin(VcrClient $client): void
    {
        self::$client = $client;
    }

    /**
     * Ends the open session: the cassette is closed, which gives back its lock and checks
     * whatever the strict mode promised, and the handle is cleared either way. Doing
     * nothing when no session is open, since both the trait and the extension call it and
     * whichever gets there first is the one that matters.
     */
    public static function end(): void
    {
        $client = self::$client;
        self::$client = null;

        $client?->close();
    }
}
