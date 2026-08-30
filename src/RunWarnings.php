<?php

declare(strict_types=1);

namespace HttpVcr;

/**
 * What the cassettes reported over a whole test run, held until the run is over.
 *
 * A warning is a finding rather than a failure — a credential-shaped value in a fresh
 * recording, a forced recording a lock made a no-op — and printing each one where it
 * happens buries it under whatever the next few hundred tests print. Collected here, they
 * come out in one block at the end, which is the only reason the sink exists (§3.4).
 *
 * Ambient rather than injected, and in the core rather than in the PHPUnit bridge, because
 * a cassette has to find it without being told (§7 decision 75): a `new VcrClient(...)`
 * written by hand in a test is the normal way to reach what `#[UseCassette]` doesn't
 * expose, and one that reported straight to standard error would interleave with the
 * runner's progress output while its neighbours waited for the block at the end.
 *
 * A harness other than PHPUnit opts in the same way the bundled extension does: `collect()`
 * when the run starts, `summary()` and `stop()` when it ends.
 */
final class RunWarnings
{
    private static ?self $current = null;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * The collector for this run, or null when nothing is collecting — in which case a
     * cassette reports to standard error as it always did.
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    public static function collect(): self
    {
        return self::$current = new self;
    }

    public static function stop(): void
    {
        self::$current = null;
    }

    /**
     * Puts a collector back, for anything that took the sink over for part of a run and
     * owes the rest of it what was there before — `null` is the same statement as
     * {@see stop()}.
     */
    public static function resume(?self $collector): void
    {
        self::$current = $collector;
    }

    public function report(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->warnings;
    }

    /**
     * Everything this run collected, as one block, or null when it collected nothing.
     */
    public function summary(): ?string
    {
        if ($this->warnings === []) {
            return null;
        }

        return sprintf(
            "\n%s\n\n%s",
            Ansi::yellow(sprintf(
                'http-vcr — %d warning%s from this run:',
                count($this->warnings),
                count($this->warnings) === 1 ? '' : 's',
            )),
            implode("\n", $this->warnings),
        );
    }
}
