<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use HttpVcr\RunWarnings;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/**
 * Prints what the run's cassettes reported, once, after the last test.
 *
 * A finding printed as it happens is a finding buried under the next few hundred tests;
 * here it is still on screen when the run ends. Standard error rather than the result
 * output, since none of this is a test result — the cassette is written and the decision
 * about it belongs to a person (§3.4).
 *
 * @internal
 */
final class ReportsWhatTheRunFound implements ExecutionFinishedSubscriber
{
    public function __construct(private readonly RunWarnings $warnings) {}

    public function notify(ExecutionFinished $event): void
    {
        $summary = $this->warnings->summary();

        if ($summary !== null) {
            file_put_contents('php://stderr', $summary);
        }

        // Collecting stops with the block that prints it. A cassette closed later — by a
        // shutdown function, or by a test helper of its own — would otherwise report into
        // a collector nothing will ever read again; with the sink gone it says so on
        // standard error, which is where an unprinted warning belongs.
        RunWarnings::stop();
    }
}
