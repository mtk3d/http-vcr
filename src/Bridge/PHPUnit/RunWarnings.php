<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

/**
 * What the cassettes reported over a whole test run, held until the run is over.
 *
 * A warning is a finding rather than a failure — a credential-shaped value in a fresh
 * recording, a forced recording a lock made a no-op — and printing each one where it
 * happens buries it under whatever the next few hundred tests print. Collected here, they
 * come out in one block at the end, which is the only reason the sink exists (§3.4).
 *
 * @internal
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
        return self::$current = new self();
    }

    public static function stop(): void
    {
        self::$current = null;
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
            "\nhttp-vcr — %d warning%s from this run:\n\n%s",
            count($this->warnings),
            count($this->warnings) === 1 ? '' : 's',
            implode("\n", $this->warnings),
        );
    }
}
