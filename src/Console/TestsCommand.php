<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `tests --provider=<name>`: which tests touch one API, and a `--filter` regex for
 * PHPUnit (§3.12).
 *
 * This is speed, never safety. What a run erases and re-records is decided by the
 * `VCR_ERASE_TAPE` selector, so the same command without any filter produces the same
 * cassettes — it just runs tests that were never going to change anything.
 *
 * The answer comes from two sources, neither enough alone: the AST scan says which test
 * opens which cassette, and the cassette says which hosts it really talked to. Only the
 * second knows that a test named after checkout also calls Stripe. It follows that a test
 * whose cassette has not been recorded yet cannot be listed — there is nothing to read —
 * which the report says outright rather than leaving as a silent gap.
 */
final class TestsCommand extends Command
{
    public function __construct()
    {
        parent::__construct('tests');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Lists the tests that touch one provider, with a ready-made PHPUnit --filter')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider name, or a host no configuration claimed')
            ->addOption('filter-only', null, InputOption::VALUE_NONE, 'Print just the regex, for a shell substitution');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $this->errorOutput($output);
        $filterOnly = $input->getOption('filter-only') === true;

        $provider = $input->getOption('provider');

        if (!is_string($provider) || $provider === '') {
            $errors->writeln('<error>Which API? Name it with --provider=<name>.</error>');

            return Command::FAILURE;
        }

        $config = Config::global();
        $scanned = (new TestScanner($config->testDirectories()))->scan();

        /** @var array<string, CassetteInventory> $inventories */
        $inventories = ['' => new CassetteInventory($config)];

        foreach ($scanned->declarations as $declaration) {
            $inventories[$declaration->directory ?? ''] ??= new CassetteInventory($config, $declaration->directory);
        }

        $known = array_keys($config->providers());

        foreach ($inventories as $inventory) {
            $known = array_merge($known, array_keys($inventory->byProvider()));
        }

        if (!in_array($provider, $known, true)) {
            $errors->writeln($this->unknown($provider, array_keys($config->providers()), $known));

            return Command::FAILURE;
        }

        $matches = [];

        foreach ($scanned->declarations as $declaration) {
            $inventory = $inventories[$declaration->directory ?? ''];

            foreach ($inventory->filesOf($declaration->declared->name) as $file) {
                foreach (array_keys($inventory->hosts($file)) as $host) {
                    if ($inventory->providerOf($host) === $provider) {
                        $matches[$declaration->name()] = $declaration->declared->name;
                    }
                }
            }
        }

        ksort($matches);

        if ($filterOnly) {
            $output->writeln($this->regex(array_keys($matches)));
        } else {
            $this->report($output, $provider, $matches, $scanned);
        }

        foreach ($inventories as $inventory) {
            foreach ($inventory->unreadable() as $failure) {
                $errors->writeln(sprintf('<error>%s</error>', $failure));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $matches test name => the cassette it was found through
     */
    private function report(OutputInterface $output, string $provider, array $matches, ScannedTests $scanned): void
    {
        if ($matches === []) {
            $output->writeln(sprintf('No test touches %s.', $provider));
            $output->writeln(
                'A test is found through its cassette, so one that has never been recorded '
                . 'cannot appear here — record it with an unfiltered run first.',
            );
        } else {
            $count = count($matches);

            $output->writeln(sprintf(
                '%d %s %s %s:',
                $count,
                $count === 1 ? 'test' : 'tests',
                $count === 1 ? 'touches' : 'touch',
                $provider,
            ));

            foreach ($matches as $test => $cassette) {
                $output->writeln(sprintf('  %s (%s)', $test, $cassette));
            }

            $output->writeln('');
            $output->writeln($this->regex(array_keys($matches)));
        }

        if ($scanned->unanalyzed !== []) {
            $output->writeln('');
            $output->writeln('<comment>Declarations the scan could not read in full:</comment>');

            foreach ($scanned->unanalyzed as $note) {
                $output->writeln('  ' . $note);
            }
        }
    }

    /**
     * PHPUnit matches this against `Class::method`, and against `Class::method#2` or
     * `Class::method with data set "x"` when a provider supplied the arguments — so the
     * tail is where a test's own name ends, not the end of the string, which would drop
     * every data set of a test that has one.
     *
     * @param list<string> $tests
     */
    private function regex(array $tests): string
    {
        if ($tests === []) {
            // Nothing matched, and an empty filter would run the whole suite — the one
            // outcome nobody asked for when they wrote --filter-only.
            return '/^(?!)/';
        }

        return '/^(' . implode('|', array_map(
            static fn (string $test): string => preg_quote($test, '/'),
            $tests,
        )) . ')(?:$|#| with data set)/';
    }

    /**
     * @param list<string> $configured
     * @param list<string> $known
     */
    private function unknown(string $provider, array $configured, array $known): string
    {
        $hosts = array_values(array_diff($known, $configured));

        return sprintf(
            "<error>There is no provider named \"%s\".</error>\nConfigured in http-vcr.php: %s\nHosts in the cassettes: %s",
            $provider,
            $configured === [] ? 'none' : implode(', ', $configured),
            $hosts === [] ? 'none' : implode(', ', $hosts),
        );
    }

    /**
     * Diagnostics go to stderr, so `--filter-only` inside a shell substitution captures the
     * regex and nothing else.
     */
    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
