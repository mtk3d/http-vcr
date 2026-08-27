<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Config;
use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\Serializer\CassetteSerializerInterface;
use HttpVcr\Serializer\JsonCassetteSerializer;
use HttpVcr\Serializer\YamlCassetteSerializer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `migrate --to=yaml`: rewrites every cassette in the project in the other format (§3.2).
 *
 * A cassette is only ever looked for under the extension its serializer owns, so switching
 * format leaves the old files on disk and invisible — which in `RecordIfAbsent` reads as a
 * project with no recordings and quietly re-records the lot. This is the one command that
 * makes the switch a deliberate, reviewable step instead.
 *
 * The two formats hold the same schema, so the rewrite is a read and a write, and the
 * cassette means exactly what it meant before. Sidecar bodies are named after the cassette
 * without its extension and so are not touched at all.
 */
final class MigrateCommand extends Command
{
    public function __construct()
    {
        parent::__construct('migrate');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Rewrites every cassette in another format, JSON to YAML or back')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'The format to write: json or yaml')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'The format to read, when it is not simply the other one')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be rewritten, and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = $input->getOption('to');
        $from = $input->getOption('from') ?? $this->opposite($to);

        if (! is_string($to) || ! is_string($from) || $this->serializerFor($to) === null || $this->serializerFor($from) === null) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                $this->refusal(is_string($to) ? $to : null, $input->getOption('from')),
            ));

            return Command::FAILURE;
        }

        if ($to === $from) {
            $output->writeln(sprintf('<error>--to and --from are both %s; there is nothing to rewrite.</error>', $to));

            return Command::FAILURE;
        }

        return $this->rewrite(
            $this->serializerFor($from),
            $this->serializerFor($to),
            $input->getOption('dry-run') === true,
            $output,
        );
    }

    private function rewrite(
        CassetteSerializerInterface $from,
        CassetteSerializerInterface $to,
        bool $dryRun,
        OutputInterface $output,
    ): int {
        $config = Config::global();
        $migrated = 0;
        $refused = false;

        foreach ($this->directories($config) as $directory) {
            $source = new CassetteEditor($config, $directory, $from);
            $target = new CassetteEditor($config, $directory, $to);

            foreach ($source->all() as $file) {
                $result = $this->one($source, $target, $file, $dryRun, $output);

                $migrated += $result ? 1 : 0;
                $refused = $refused || ! $result;
            }
        }

        $output->writeln($this->summary($migrated, $to, $dryRun));

        return $refused ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * One cassette, or a reason it was left where it is. A file that cannot be read and a
     * name already taken in the target format are both refusals rather than skips: either
     * one means the project ends up split across two formats, which is the state this
     * command exists to leave behind.
     */
    private function one(
        CassetteEditor $source,
        CassetteEditor $target,
        string $file,
        bool $dryRun,
        OutputInterface $output,
    ): bool {
        if ($target->exists($file)) {
            $output->writeln(sprintf(
                '<error>%s already exists; %s was left alone.</error>',
                $target->describe($file),
                $source->describe($file),
            ));

            return false;
        }

        try {
            $cassette = $source->read($file);
        } catch (CassetteFormatException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return false;
        }

        $output->writeln(sprintf('  %s → %s', $source->describe($file), $target->describe($file)));

        if ($dryRun) {
            return true;
        }

        // Under the lock of the file being replaced, so a test run appending to it cannot
        // land a write between the read and the delete.
        $source->locking($file, static function () use ($source, $target, $file, $cassette): void {
            $target->write($file, $cassette);
            $source->delete($file);
        });

        return true;
    }

    private function summary(int $migrated, CassetteSerializerInterface $to, bool $dryRun): string
    {
        if ($migrated === 0) {
            return "\nNo cassettes to rewrite.";
        }

        return sprintf(
            "\n%d cassette%s %s in %s.",
            $migrated,
            $migrated === 1 ? '' : 's',
            $dryRun ? 'would be rewritten' : 'rewritten',
            strtoupper($to->fileExtension()),
        );
    }

    private function refusal(?string $to, mixed $from): string
    {
        if ($to === null) {
            return 'Which format? Name it with --to=yaml or --to=json.';
        }

        $unknown = $this->serializerFor($to) === null ? $to : (is_string($from) ? $from : '');

        return sprintf('There is no "%s" cassette format. This command rewrites between json and yaml.', $unknown);
    }

    private function serializerFor(mixed $format): ?CassetteSerializerInterface
    {
        return match ($format) {
            'json' => new JsonCassetteSerializer,
            'yaml', 'yml' => new YamlCassetteSerializer,
            default => null,
        };
    }

    private function opposite(mixed $format): ?string
    {
        return match ($format) {
            'json' => 'yaml',
            'yaml', 'yml' => 'json',
            default => null,
        };
    }

    /**
     * @return list<string|null>
     */
    private function directories(Config $config): array
    {
        return [null, ...(new TestScanner($config->testDirectories()))->scan()->directories()];
    }
}
