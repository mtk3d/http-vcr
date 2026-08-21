<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Cassette\Interaction;
use HttpVcr\Config;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `lock` and `unlock`: sets or clears `"locked": true` on recorded interactions (§3.1).
 *
 * Convenience, not the only way in — the same field can be edited straight in the JSON,
 * and the point of a lock is precisely that it shows up as a line in a diff. What the
 * command adds is not having to hand-edit JSON to get there, and not having to work out
 * which scope files a cassette name currently has.
 *
 * One class for both directions because they differ in a single boolean: two classes would
 * be the same forty lines twice, and the pair would drift.
 */
final class LockCommand extends Command
{
    public function __construct(private readonly bool $lock)
    {
        parent::__construct($lock ? 'lock' : 'unlock');
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->lock
                ? 'Protects recorded interactions, so forced recording leaves them alone'
                : 'Lifts that protection, so forced recording refreshes them again')
            ->addArgument('cassette', InputArgument::REQUIRED, 'Cassette name, without extension or scope')
            ->addOption(
                'interaction',
                null,
                InputOption::VALUE_REQUIRED,
                'Which interaction, counting from 1; every one of them without this',
            )
            ->addOption(
                'scope',
                null,
                InputOption::VALUE_REQUIRED,
                'Narrow to one scope file; every scope of the cassette without this',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $editor = new CassetteEditor(Config::global());

        $argument = $input->getArgument('cassette');
        $name = is_string($argument) ? $argument : '';
        $scope = $this->option($input, 'scope');
        $position = $this->position($input);

        $files = $editor->files($name, $scope);

        if ($files === []) {
            $output->writeln(sprintf(
                '<error>No cassette named "%s"%s in %s.</error>',
                $name,
                $scope === null ? '' : sprintf(' with scope "%s"', $scope),
                Config::global()->cassetteDirectory(),
            ));

            return Command::FAILURE;
        }

        foreach ($files as $file) {
            $failure = $editor->locking($file, fn (): ?string => $this->apply($editor, $file, $position, $output));

            if ($failure !== null) {
                $output->writeln(sprintf('<error>%s</error>', $failure));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return string|null the reason this file could not be edited, null when it was
     */
    private function apply(CassetteEditor $editor, string $file, ?int $position, OutputInterface $output): ?string
    {
        $cassette = $editor->read($file);
        $count = count($cassette->interactions);

        if ($position !== null && $position > $count) {
            return sprintf(
                '%s has %d interaction%s, so there is no #%d to %s.',
                $editor->describe($file),
                $count,
                $count === 1 ? '' : 's',
                $position,
                (string) $this->getName(),
            );
        }

        $changed = 0;
        $interactions = [];

        foreach ($cassette->interactions as $index => $interaction) {
            $interactions[] = $this->covers($index, $position) && $interaction->locked !== $this->lock
                ? $this->flip($interaction, $changed)
                : $interaction;
        }

        if ($changed > 0) {
            $editor->write($file, $cassette->withInteractions($interactions));
        }

        $output->writeln($this->report($editor->describe($file), $changed, $position, $count));

        return null;
    }

    private function flip(Interaction $interaction, int &$changed): Interaction
    {
        ++$changed;

        return $interaction->withLocked($this->lock);
    }

    private function covers(int $index, ?int $position): bool
    {
        return $position === null || $index + 1 === $position;
    }

    private function report(string $location, int $changed, ?int $position, int $count): string
    {
        $verb = $this->lock ? 'locked' : 'unlocked';

        if ($changed === 0) {
            return sprintf(
                '%s: nothing to do, %s already %s.',
                $location,
                $position === null ? 'all of it is' : sprintf('interaction #%d is', $position),
                $verb,
            );
        }

        if ($position !== null) {
            return sprintf('%s: interaction #%d %s.', $location, $position, $verb);
        }

        return sprintf(
            '%s: %d of %d interactions %s.',
            $location,
            $changed,
            $count,
            $verb,
        );
    }

    private function position(InputInterface $input): ?int
    {
        $value = $this->option($input, 'interaction');

        if ($value === null) {
            return null;
        }

        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '--interaction takes a position in the cassette counting from 1, not "%s".',
                $value,
            ));
        }

        return (int) $value;
    }

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
