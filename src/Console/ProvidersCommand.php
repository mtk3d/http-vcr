<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Config;
use HttpVcr\Provider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `providers`: the providers configured in `http-vcr.php`, held against what the cassettes
 * on disk actually contain (§3.12).
 *
 * The second half of the report is the point of it. A host no configuration claimed is a
 * provider of its own — `VCR_ERASE_TAPE=@api.stripe.com` targets it like any other — so
 * nothing is broken about that list; it is the shortlist of integrations worth naming,
 * because a name is what carries `requiresEnv`.
 */
final class ProvidersCommand extends Command
{
    public function __construct()
    {
        parent::__construct('providers');
    }

    protected function configure(): void
    {
        $this->setDescription('Shows the configured providers next to the hosts the cassettes really use');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Config::global();
        $inventory = new CassetteInventory($config);
        $recorded = $inventory->byProvider();
        $configured = $config->providers();

        $this->writeConfigured($output, $configured, $recorded);

        $output->writeln('');

        if ($recorded === []) {
            $output->writeln('No cassettes have been recorded yet, so there is nothing to hold the configuration against.');
        } else {
            $this->writeImplicit($output, array_diff_key($recorded, $configured));
        }

        foreach ($inventory->unreadable() as $failure) {
            $output->writeln(sprintf('<error>%s</error>', $failure));
        }

        return $inventory->unreadable() === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, Provider>                                              $configured
     * @param array<string, array{cassettes: int, interactions: int, hosts: list<string>}>  $recorded
     */
    private function writeConfigured(OutputInterface $output, array $configured, array $recorded): void
    {
        if ($configured === []) {
            $output->writeln('No providers are configured in http-vcr.php.');

            return;
        }

        $rows = [];

        foreach ($configured as $name => $provider) {
            $counts = $recorded[$name] ?? ['cassettes' => 0, 'interactions' => 0];

            $rows[] = [
                $name,
                $provider->hosts === [] ? '—' : implode(', ', $provider->hosts),
                $provider->requiresEnv === [] ? '—' : implode(', ', $provider->requiresEnv),
                $this->counts($counts['cassettes'], $counts['interactions']),
            ];
        }

        $this->writeTable($output, $rows);
    }

    /**
     * @param array<string, array{cassettes: int, interactions: int, hosts: list<string>}> $implicit
     */
    private function writeImplicit(OutputInterface $output, array $implicit): void
    {
        if ($implicit === []) {
            $output->writeln('Every host in the cassettes belongs to a configured provider.');

            return;
        }

        $output->writeln('<comment>Implicit (addressable by host, no requiresEnv):</comment>');

        $rows = [];

        foreach ($implicit as $host => $counts) {
            $rows[] = ['  ' . $host, $this->counts($counts['cassettes'], $counts['interactions'])];
        }

        $this->writeTable($output, $rows);
    }

    /**
     * @param list<list<string>> $rows
     */
    private function writeTable(OutputInterface $output, array $rows): void
    {
        $widths = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, mb_strlen($cell));
            }
        }

        foreach ($rows as $row) {
            $line = '';

            foreach ($row as $column => $cell) {
                $line .= $column === array_key_last($row)
                    ? $cell
                    : $cell . str_repeat(' ', $widths[$column] - mb_strlen($cell) + 2);
            }

            $output->writeln($line);
        }
    }

    private function counts(int $cassettes, int $interactions): string
    {
        return sprintf(
            '%d cassette%s, %d interaction%s',
            $cassettes,
            $cassettes === 1 ? '' : 's',
            $interactions,
            $interactions === 1 ? '' : 's',
        );
    }
}
