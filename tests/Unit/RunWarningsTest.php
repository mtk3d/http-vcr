<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use HttpVcr\Ansi;
use HttpVcr\RunWarnings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunWarnings::class)]
final class RunWarningsTest extends TestCase
{
    protected function tearDown(): void
    {
        RunWarnings::stop();
    }

    public function testARunWithNothingToSayHasNoSummaryToPrint(): void
    {
        self::assertNull((new RunWarnings)->summary());
    }

    public function testWhatTheCassettesReportedComesOutAsOneBlock(): void
    {
        $warnings = new RunWarnings;

        $warnings->report("http-vcr: tests/Cassettes/payments.json\n  a credential-shaped value\n");
        $warnings->report("http-vcr: tests/Cassettes/checkout.json\n  cassette fully locked\n");

        $summary = (string) $warnings->summary();

        self::assertCount(2, $warnings->all());
        self::assertStringContainsString('2 warnings from this run', $summary);
        self::assertStringContainsString('payments.json', $summary);
        self::assertStringContainsString('checkout.json', $summary);
    }

    /**
     * Only the block's own heading is colored — the warnings inside it arrived already
     * styled by whatever built them, and re-wrapping them here would nest escape codes.
     */
    public function testTheHeadingIsColoredWhereColorIsAvailable(): void
    {
        Ansi::assume(true);

        try {
            $warnings = new RunWarnings;
            $warnings->report("a warning\n");

            $summary = (string) $warnings->summary();
        } finally {
            Ansi::assume(null);
        }

        self::assertSame("\n\033[33mhttp-vcr — 1 warning from this run:\033[0m\n\na warning\n", $summary);
    }

    public function testNothingIsCollectingUntilARunSaysSo(): void
    {
        self::assertNull(RunWarnings::current());

        $collector = RunWarnings::collect();

        self::assertSame($collector, RunWarnings::current());

        RunWarnings::stop();

        self::assertNull(RunWarnings::current());
    }
}
