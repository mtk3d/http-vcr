<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use HttpVcr\Ansi;
use PHPUnit\Runner\Extension\Extension as PHPUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The entry the library cannot make for itself (§3.12):
 *
 * ```xml
 * <extensions>
 *     <bootstrap class="HttpVcr\Bridge\PHPUnit\Extension"/>
 * </extensions>
 * ```
 *
 * PHPUnit has no auto-discovery for extensions, and without this entry `#[UseCassette]` is
 * decoration nobody reads — the worst failure mode there is, since the test then makes
 * real requests and says nothing about it.
 *
 * Registered, it opens the cassette a test declared before the test prepares (which is
 * before `setUp()`, so the client is available and still configurable there), closes
 * whatever is left open afterwards, and prints what the run's cassettes reported once at
 * the end rather than scattered through the output.
 */
final class Extension implements PHPUnitExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        // A run told not to use color means the whole run, including the block printed at
        // the end of it. The other way round is not the same statement — PHPUnit colors
        // its own output on a terminal it detected itself — so that case is left to Ansi.
        if (! $configuration->colors()) {
            Ansi::assume(false);
        }

        $warnings = RunWarnings::collect();

        $facade->registerSubscribers(
            new OpensDeclaredCassette(new CassetteFactory),
            new ClosesOpenCassette,
            new ReportsWhatTheRunFound($warnings),
        );
    }
}
