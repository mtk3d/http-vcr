<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Ansi;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Config;
use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\Hook\Redaction;
use HttpVcr\Hook\RedactionHooks;
use HttpVcr\SecretFinding;
use HttpVcr\SecretScanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * `scan-secrets`: the full, manual pass of the scanner that otherwise runs by itself after
 * every recording (§3.4/§3.12).
 *
 * Two things it adds over the automatic check: it reads every cassette in the project
 * rather than what one run happened to record, and it can fail, so a CI step may be made
 * blocking. It reports rather than repairs — the library recorded exactly what it was
 * asked to, and whether `sk_live_…` in a payment test is a leak or a fixture describing an
 * error response needs context no tool here has.
 *
 * The test is the shape of the value in the file, not which `redact()` rules exist: the
 * command runs no tests, so it cannot know about a rule registered in a `setUp()`. What it
 * can read is `http-vcr.php`, and a rule declared there names a literal secret — so any
 * cassette still carrying that literal is reported however innocent it looks.
 */
final class ScanSecretsCommand extends Command
{
    public function __construct()
    {
        parent::__construct('scan-secrets');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Sweeps every cassette for credential-shaped values')
            ->addArgument(
                'cassette',
                InputArgument::OPTIONAL,
                'One cassette to sweep, scope files included — every cassette in the project by default',
            )
            ->addOption(
                'provider',
                null,
                InputOption::VALUE_REQUIRED,
                'Only interactions belonging to this provider, or to this host',
            )
            ->addOption(
                'redact',
                null,
                InputOption::VALUE_NONE,
                'Ask about each finding and replace the ones confirmed with a placeholder',
            )
            ->addOption(
                'fail-on-findings',
                null,
                InputOption::VALUE_NONE,
                'Exit non-zero when anything is found, for a CI step meant to block',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Config::global();
        $scanner = new SecretScanner;
        $secrets = $this->declaredSecrets($config);

        $named = $input->getArgument('cassette');
        $named = is_string($named) ? $named : null;
        $provider = $input->getOption('provider');
        $provider = is_string($provider) && $provider !== '' ? $provider : null;
        $redacting = $input->getOption('redact') === true;

        // Nobody to ask means nobody decided, and a pipeline quietly rewriting cassettes is
        // the opposite of what this is for.
        if ($redacting && ! $input->isInteractive()) {
            $output->writeln('<error>--redact needs a terminal to ask on, and this run has none.</error>');

            return Command::FAILURE;
        }

        $found = 0;
        $cassettes = 0;
        $scanned = 0;
        $unreadable = false;
        $matched = false;
        $ofProvider = false;

        foreach ($this->directories($config) as $directory) {
            $editor = new CassetteEditor($config, $directory);

            foreach ($named === null ? $editor->all() : $editor->files($named, null) as $file) {
                $matched = true;
                $scanned++;

                try {
                    $cassette = $editor->read($file);
                } catch (CassetteFormatException $exception) {
                    $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
                    $unreadable = true;

                    continue;
                }

                $findings = [];

                foreach ($cassette->interactions as $position => $interaction) {
                    if ($provider !== null && ! $this->belongsTo($interaction, $provider, $config)) {
                        continue;
                    }

                    $ofProvider = true;

                    foreach ([...$scanner->scan($interaction), ...$this->literals($interaction, $secrets)] as $finding) {
                        $findings[] = [$position, $finding];
                    }
                }

                if ($findings === []) {
                    continue;
                }

                $cassettes++;
                $found += count($findings);

                $output->writeln(sprintf('<info>%s</info>', $editor->describe($file)));

                if ($redacting) {
                    $this->redact($editor, $file, $findings, $input, $output);
                } else {
                    foreach ($findings as [$position, $finding]) {
                        $output->writeln($this->describe($position, $finding));
                    }
                }

                $output->writeln('');
            }
        }

        if (! $matched && $named !== null) {
            $output->writeln(sprintf('<error>No cassette named "%s" is on disk.</error>', $named));

            return Command::FAILURE;
        }

        if (! $ofProvider && $provider !== null) {
            $output->writeln($this->unknownProvider($provider, $config));

            return Command::FAILURE;
        }

        $output->writeln($this->summary($found, $cassettes, $scanned));

        if ($unreadable) {
            return Command::FAILURE;
        }

        return $found > 0 && $input->getOption('fail-on-findings') === true
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * Coloured through Ansi rather than the formatter's tags, so a finding looks the same
     * here as it does in the warning a run prints (§7 decision 66) — the application hands
     * Ansi the console's own --ansi/--no-ansi answer.
     */
    private function describe(int $position, SecretFinding $finding): string
    {
        return sprintf(
            '  #%d %s — %s (%d chars)',
            $position + 1,
            Ansi::bold($finding->location),
            Ansi::red('"'.$finding->excerpt().'"'),
            $finding->length(),
        );
    }

    /**
     * Whether this interaction's host answers to the name given: a configured provider's,
     * or the host itself, which is a provider of its own without anyone declaring it — the
     * same rule `VCR_ERASE_TAPE=@name` follows (§3.12).
     */
    private function belongsTo(Interaction $interaction, string $provider, Config $config): bool
    {
        $host = parse_url($interaction->request->uri, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        return strcasecmp($config->providerFor($host) ?? $host, $provider) === 0;
    }

    private function unknownProvider(string $provider, Config $config): string
    {
        $configured = array_keys($config->providers());

        return sprintf(
            '<error>Nothing recorded belongs to "%s". %s</error>',
            $provider,
            $configured === []
                ? 'No providers are configured, so a name here is a host in one of the cassettes.'
                : 'Configured providers: '.implode(', ', $configured).'.',
        );
    }

    /**
     * Asks about each finding in one cassette and rewrites the file with whatever was
     * confirmed — every occurrence of the value, not only the one that was found: a
     * credential repeated in three places must not survive in two.
     *
     * One way, and said so: the value is replaced in the file and http-vcr has nothing to
     * put back. Replay keeps working for a response body, which nothing matches on. A value
     * in the request is matched on, so the same substitution has to exist in `http-vcr.php`
     * for a live request to line up with the recording again — which is what the note after
     * such a finding is for.
     *
     * @param  list<array{int, SecretFinding}>  $findings
     */
    private function redact(
        CassetteEditor $editor,
        string $file,
        array $findings,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $questions = $this->getHelper('question');

        if (! $questions instanceof QuestionHelper) {
            return;
        }

        /** @var list<array{string, string}> $confirmed value and the placeholder taking its place */
        $confirmed = [];
        $answered = [];

        foreach ($findings as [$position, $finding]) {
            $output->writeln($this->describe($position, $finding));

            if (in_array($finding->value, $answered, true)) {
                continue;
            }

            $answered[] = $finding->value;

            if ($questions->ask($input, $output, new ConfirmationQuestion('     Redact it? [y/N] ', false)) !== true) {
                continue;
            }

            $placeholder = $questions->ask($input, $output, new Question(
                sprintf('     Placeholder [%s] ', $this->placeholderFor($finding)),
                $this->placeholderFor($finding),
            ));

            $confirmed[] = [$finding->value, is_string($placeholder) && $placeholder !== '' ? $placeholder : $this->placeholderFor($finding)];

            if (str_starts_with($finding->location, 'request.')) {
                $output->writeln(
                    '     This one sits in the request, which is what replay matches on — '
                    .'it will only match again if http-vcr.php redacts the same field.',
                );
            }
        }

        if ($confirmed === []) {
            return;
        }

        $editor->locking($file, static function () use ($editor, $file, $confirmed): void {
            $cassette = $editor->read($file);
            $hooks = new RedactionHooks;

            // The four headers redacted with no configuration at all are opted out of here:
            // this pass must change what was confirmed and nothing else, and a cassette
            // imported from a HAR can still be carrying one of them in the clear.
            $hooks->includeSensitiveHeaders(['Authorization', 'Proxy-Authorization', 'Cookie', 'Set-Cookie']);

            foreach ($confirmed as [$value, $placeholder]) {
                $hooks->redact($placeholder, static fn (): string => $value);
            }

            $editor->write($file, $cassette->withInteractions(array_map(
                static fn (Interaction $interaction): Interaction => $hooks->beforeRecord($interaction),
                $cassette->interactions,
            )));
        });

        $output->writeln(sprintf(
            '  %s — one-way: the original values are not in this file any more.',
            $this->redacted(count($confirmed)),
        ));
    }

    private function redacted(int $count): string
    {
        return sprintf('redacted %d value%s', $count, $count === 1 ? '' : 's');
    }

    /**
     * The placeholder offered for a finding, in the convention the redaction rules use:
     * the field's own name where the location names one, and a neutral one where the value
     * was found loose in a body.
     */
    private function placeholderFor(SecretFinding $finding): string
    {
        if (preg_match('/\(([^)]+)\)/', $finding->location, $found) === 1) {
            $name = (string) preg_replace('#^.*/#', '', $found[1]);

            return Redaction::placeholderFor($name);
        }

        if (str_contains($finding->location, '.headers.')) {
            return Redaction::placeholderFor(substr($finding->location, (int) strrpos($finding->location, '.') + 1));
        }

        return Redaction::placeholderFor('secret');
    }

    /**
     * Every cassette directory the project uses: the configured one, plus whatever a test
     * class put beside itself with `#[CassetteDirectory]` (§3.12). A module keeping its own
     * cassettes is precisely the directory a sweep must not miss.
     *
     * @return list<string|null> null being the configured default
     */
    private function directories(Config $config): array
    {
        return [null, ...(new TestScanner($config->testDirectories()))->scan()->directories()];
    }

    /**
     * The literal values `http-vcr.php` declares as things to redact, for the cassettes
     * recorded before that configuration existed. A provider that yields nothing here —
     * an environment variable the CLI does not have — simply drops out.
     *
     * @return array<string, string> placeholder => the value it stands for
     */
    private function declaredSecrets(Config $config): array
    {
        $secrets = [];

        foreach ($config->redactions() as $placeholder => $provider) {
            $value = $provider();

            if (is_scalar($value) && (string) $value !== '') {
                $secrets[$placeholder] = (string) $value;
            }
        }

        return $secrets;
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<SecretFinding>
     */
    private function literals(Interaction $interaction, array $secrets): array
    {
        if ($secrets === []) {
            return [];
        }

        $places = ['request.uri' => $interaction->request->uri];

        foreach ($interaction->request->headers as $name => $values) {
            $places['request.headers.'.strtolower($name)] = implode(', ', $values);
        }

        if ($interaction->request->bodyEncoding === null) {
            $places['request.body'] = $interaction->request->body;
        }

        if ($interaction->response !== null) {
            foreach ($interaction->response->headers as $name => $values) {
                $places['response.headers.'.strtolower($name)] = implode(', ', $values);
            }

            if ($interaction->response->bodyEncoding === null) {
                $places['response.body'] = $interaction->response->body;
            }
        }

        if ($interaction->error !== null) {
            $places['error.message'] = $interaction->error->message;
        }

        $findings = [];

        foreach ($places as $location => $content) {
            foreach ($secrets as $placeholder => $secret) {
                if (str_contains($content, $secret)) {
                    $findings[] = new SecretFinding(
                        sprintf('%s — the value %s stands for', $location, $placeholder),
                        $secret,
                    );
                }
            }
        }

        return $findings;
    }

    private function summary(int $found, int $cassettes, int $scanned): string
    {
        if ($scanned === 0) {
            return 'There are no cassettes to scan.';
        }

        if ($found === 0) {
            return sprintf(
                'No credential-shaped values in %d cassette%s.',
                $scanned,
                $scanned === 1 ? '' : 's',
            );
        }

        return sprintf(
            '%d finding%s in %d of %d cassettes.',
            $found,
            $found === 1 ? '' : 's',
            $cassettes,
            $scanned,
        );
    }
}
