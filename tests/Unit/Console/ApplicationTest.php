<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Console;

use HttpVcr\Ansi;
use HttpVcr\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(Application::class)]
final class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        Ansi::assume(null);
    }

    /**
     * The console has already decided this — from a terminal, from `--ansi`, from
     * `--no-ansi` — and a warning printed from inside a command has to agree with the
     * command's own output rather than detect it a second time.
     */
    public function testTheConsolesAnswerAboutColorIsTheOneTheWarningsUse(): void
    {
        Ansi::assume(false);

        $this->listCommands(decorated: true);

        self::assertTrue(Ansi::enabled());

        $this->listCommands(decorated: false);

        self::assertFalse(Ansi::enabled());
    }

    private function listCommands(bool $decorated): void
    {
        $application = new Application;
        $application->setAutoExit(false);

        $application->run(
            new ArrayInput(['command' => 'list']),
            new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated),
        );
    }
}
