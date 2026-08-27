<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Cassette\Interaction;
use HttpVcr\Config;
use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\SecretFinding;
use HttpVcr\SecretScanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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

        $found = 0;
        $cassettes = 0;
        $scanned = 0;
        $unreadable = false;

        foreach ($this->directories($config) as $directory) {
            $editor = new CassetteEditor($config, $directory);

            foreach ($editor->all() as $file) {
                $scanned++;

                try {
                    $cassette = $editor->read($file);
                } catch (CassetteFormatException $exception) {
                    $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
                    $unreadable = true;

                    continue;
                }

                $lines = [];

                foreach ($cassette->interactions as $position => $interaction) {
                    foreach ([...$scanner->scan($interaction), ...$this->literals($interaction, $secrets)] as $finding) {
                        $lines[] = sprintf(
                            '  #%d %s — "%s" (%d chars)',
                            $position + 1,
                            $finding->location,
                            $finding->excerpt(),
                            $finding->length(),
                        );
                    }
                }

                if ($lines === []) {
                    continue;
                }

                $cassettes++;
                $found += count($lines);

                $output->writeln(sprintf('<info>%s</info>', $editor->describe($file)));

                foreach ($lines as $line) {
                    $output->writeln($line);
                }

                $output->writeln('');
            }
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
