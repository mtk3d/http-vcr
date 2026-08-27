<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Ansi;
use HttpVcr\Config;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `vendor/bin/http-vcr` (§3.12).
 *
 * Nothing here is reachable from the record/replay path: symfony/console and
 * nikic/php-parser are regular dependencies of the package so that the commands work
 * straight after `composer require --dev`, and the core never touches either (§1).
 */
final class Application extends ConsoleApplication
{
    public function __construct()
    {
        parent::__construct('http-vcr');

        // addCommands(), not add()/addCommand(): the package supports symfony/console
        // ^6.4 || ^7.0 || ^8.0, and the two singular methods do not both exist across that
        // range — add() was removed in 8.0, addCommand() only arrived in 7.4.
        $this->addCommands([
            new StaleCommand,
            new ProvidersCommand,
            new TestsCommand,
            new ScanSecretsCommand,
            new MigrateCommand,
            new LockCommand(lock: true),
            new LockCommand(lock: false),
        ]);
    }

    /**
     * Reading --config here rather than inside a command: the configuration has to be in
     * place before anything asks Config::global() where the cassettes are, and every
     * command asks.
     *
     * The console has already worked out whether its output is decorated — a terminal,
     * `--ansi`, `--no-ansi` — so the core is told the answer rather than detecting it a
     * second time and possibly disagreeing about a warning printed from inside a command.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getParameterOption('--config');

        if (is_string($path) && $path !== '') {
            Config::useFile($path);
        }

        Ansi::assume($output->isDecorated());

        return parent::doRun($input, $output);
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        $definition->addOption(new InputOption(
            'config',
            null,
            InputOption::VALUE_REQUIRED,
            'The http-vcr.php to work from, instead of the one found by walking up from here',
        ));

        return $definition;
    }
}
