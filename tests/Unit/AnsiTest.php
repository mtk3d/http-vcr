<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use HttpVcr\Ansi;
use PHPUnit\Framework\TestCase;

final class AnsiTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $restore = [];

    protected function tearDown(): void
    {
        Ansi::assume(null);

        foreach ($this->restore as $name => $value) {
            $this->env($name, $value === false ? null : $value);
        }
    }

    public function testStylesWrapTheTextWhenColorIsOn(): void
    {
        Ansi::assume(true);

        $this->assertSame("\033[1mloud\033[0m", Ansi::bold('loud'));
        $this->assertSame("\033[33mcareful\033[0m", Ansi::yellow('careful'));
        $this->assertSame("\033[31mwrong\033[0m", Ansi::red('wrong'));
    }

    public function testStylesReturnTheTextUntouchedWhenColorIsOff(): void
    {
        Ansi::assume(false);

        $this->assertSame('loud', Ansi::bold('loud'));
        $this->assertSame('careful', Ansi::yellow('careful'));
        $this->assertSame('wrong', Ansi::red('wrong'));
    }

    public function testNoColorTurnsColorOffWhateverElseIsSet(): void
    {
        $this->env('NO_COLOR', '1');
        $this->env('FORCE_COLOR', '1');
        Ansi::assume(null);

        $this->assertFalse(Ansi::enabled());
    }

    public function testForceColorTurnsColorOnWhereThereIsNoTerminal(): void
    {
        $this->env('NO_COLOR', null);
        $this->env('FORCE_COLOR', '1');
        Ansi::assume(null);

        $this->assertTrue(Ansi::enabled());
    }

    public function testForceColorSetToZeroTurnsColorOff(): void
    {
        $this->env('NO_COLOR', null);
        $this->env('FORCE_COLOR', '0');
        Ansi::assume(null);

        $this->assertFalse(Ansi::enabled());
    }

    public function testATerminalThatCannotRenderColorGetsNone(): void
    {
        $this->env('NO_COLOR', null);
        $this->env('TERM', 'dumb');
        Ansi::assume(null);

        $this->assertFalse(Ansi::enabled());
    }

    public function testTheDecisionIsMadeOnceAndReusedUntilItIsReset(): void
    {
        $this->env('NO_COLOR', null);
        $this->env('FORCE_COLOR', '1');
        Ansi::assume(null);
        $this->assertTrue(Ansi::enabled());

        $this->env('FORCE_COLOR', null);
        $this->env('NO_COLOR', '1');

        $this->assertTrue(Ansi::enabled(), 'a decision already made is not re-taken mid-run');

        Ansi::assume(null);

        $this->assertFalse(Ansi::enabled());
    }

    /**
     * All three places `Environment::read()` looks, so a variable set by the suite's own
     * configuration can be taken back out for the length of one test.
     */
    private function env(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->restore)) {
            $this->restore[$name] = getenv($name);
        }

        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}
