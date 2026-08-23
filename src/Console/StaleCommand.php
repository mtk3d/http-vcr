<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use DateInterval;
use DateTimeImmutable;
use HttpVcr\Cassette\Staleness;
use HttpVcr\Config;
use HttpVcr\Exception\CassetteFormatException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `stale`: which recorded interactions have outlived the `staleAfter` their test declared
 * (§3.7, §3.12).
 *
 * Meant as a separate CI step that reports rather than gates. Crossing a threshold is a
 * fact about the clock, and the same commit run an hour apart would otherwise pass in the
 * merge request and fail on the default branch — so a finding here never fails the
 * command. What does fail it is a cassette that cannot be read at all, which is a defect
 * in the file rather than a verdict about its age.
 *
 * The threshold comes out of `#[UseCassette(staleAfter: ...)]` by the same AST scan
 * `tests` uses, so no test class is loaded and nothing is executed. A cassette no test
 * declares a threshold for is not a finding: checking nothing is the correct opt-in state.
 */
final class StaleCommand extends Command
{
    public function __construct()
    {
        parent::__construct('stale');
    }

    protected function configure(): void
    {
        $this->setDescription('Lists recorded interactions that have outlived their declared staleAfter');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Config::global();
        $scanned = (new TestScanner($config->testDirectories()))->scan();
        $staleness = new Staleness($config->clock());
        $now = $config->clock()->now();

        $thresholds = $this->thresholds($scanned, $output);

        $interactions = 0;
        $cassettes = 0;
        $unreadable = false;

        foreach ($thresholds as $threshold) {
            $editor = new CassetteEditor($config, $threshold['directory']);

            foreach ($editor->files($threshold['name'], null) as $file) {
                try {
                    $cassette = $editor->read($file);
                } catch (CassetteFormatException $exception) {
                    $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
                    $unreadable = true;

                    continue;
                }

                $stale = $staleness->in($cassette, $threshold['interval']);

                if ($stale === []) {
                    continue;
                }

                $cassettes++;
                $interactions += count($stale);

                $output->writeln(sprintf(
                    '<info>%s</info> (%s)',
                    $editor->describe($file),
                    $this->duration($threshold['interval']),
                ));

                foreach ($stale as $position => $interaction) {
                    $output->writeln(sprintf(
                        '  #%d %s %s — recorded %s, %s past',
                        $position + 1,
                        $interaction->request->method,
                        $interaction->request->uri,
                        $interaction->recordedAt->format('Y-m-d H:i'),
                        $this->since($staleness->expiryOf($interaction, $threshold['interval']), $now),
                    ));
                }

                $output->writeln('');
            }
        }

        $output->writeln($this->summary($thresholds, $interactions, $cassettes));

        if ($scanned->unanalyzed !== []) {
            $output->writeln('');
            $output->writeln('<comment>Declarations the scan could not read in full:</comment>');

            foreach ($scanned->unanalyzed as $note) {
                $output->writeln('  '.$note);
            }
        }

        return $unreadable ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * One entry per cassette that has a threshold to check.
     *
     * Two tests naming the same cassette with different intervals is left unresolved on
     * purpose: either answer is somebody's mistake, and picking one would report an age
     * against a threshold nobody wrote.
     *
     * @return list<array{directory: string|null, name: string, interval: DateInterval}>
     */
    private function thresholds(ScannedTests $scanned, OutputInterface $output): array
    {
        /** @var array<string, array{directory: string|null, name: string, declared: array<string, array{interval: DateInterval, by: list<string>}>}> $targets */
        $targets = [];

        foreach ($scanned->declarations as $declaration) {
            $interval = $declaration->declared->staleAfter;

            if ($interval === null) {
                continue;
            }

            $cassette = $declaration->declared->name;
            $key = ($declaration->directory ?? '')."\0".$cassette;

            $targets[$key] ??= ['directory' => $declaration->directory, 'name' => $cassette, 'declared' => []];

            $duration = $this->duration($interval);
            $targets[$key]['declared'][$duration] ??= ['interval' => $interval, 'by' => []];
            $targets[$key]['declared'][$duration]['by'][] = $declaration->name();
        }

        $thresholds = [];

        foreach ($targets as $target) {
            if (count($target['declared']) > 1) {
                $output->writeln(sprintf(
                    '<comment>%s: conflicting thresholds declared (%s) — skipped.</comment>',
                    $target['name'],
                    implode(', ', array_map(
                        static fn (string $duration, array $declared): string => sprintf('%s by %s', $duration, implode(' and ', $declared['by'])),
                        array_keys($target['declared']),
                        array_values($target['declared']),
                    )),
                ));

                continue;
            }

            foreach ($target['declared'] as $declared) {
                $thresholds[] = [
                    'directory' => $target['directory'],
                    'name' => $target['name'],
                    'interval' => $declared['interval'],
                ];
            }
        }

        return $thresholds;
    }

    /**
     * @param  list<array{directory: string|null, name: string, interval: DateInterval}>  $thresholds
     */
    private function summary(array $thresholds, int $interactions, int $cassettes): string
    {
        if ($thresholds === []) {
            return 'Nothing has a threshold to check.';
        }

        if ($interactions === 0) {
            return sprintf(
                'Nothing is past its threshold. %s checked.',
                $this->plural(count($thresholds), 'cassette'),
            );
        }

        return sprintf(
            '%s in %s %s past its threshold.',
            $this->plural($interactions, 'interaction'),
            $this->plural($cassettes, 'cassette'),
            $interactions === 1 ? 'is' : 'are',
        );
    }

    /**
     * How far past its expiry an interaction is, in the largest unit that says something:
     * "3 days" rather than "3 days, 4 hours, 11 minutes", which nobody acts on differently.
     */
    private function since(DateTimeImmutable $expiry, DateTimeImmutable $now): string
    {
        $elapsed = $expiry->diff($now);
        $days = $elapsed->days === false ? $elapsed->d : $elapsed->days;

        if ($days > 0) {
            return $this->plural($days, 'day');
        }

        if ($elapsed->h > 0) {
            return $this->plural($elapsed->h, 'hour');
        }

        return $this->plural($elapsed->i, 'minute');
    }

    private function duration(DateInterval $interval): string
    {
        $fields = [
            [$interval->y, 'year'],
            [$interval->m, 'month'],
            [$interval->d, 'day'],
            [$interval->h, 'hour'],
            [$interval->i, 'minute'],
            [$interval->s, 'second'],
        ];

        $parts = [];

        foreach ($fields as [$value, $unit]) {
            if ($value > 0) {
                $parts[] = $this->plural($value, $unit);
            }
        }

        return $parts === [] ? 'no time at all' : implode(' ', $parts);
    }

    private function plural(int $count, string $unit): string
    {
        return sprintf('%d %s%s', $count, $unit, $count === 1 ? '' : 's');
    }
}
