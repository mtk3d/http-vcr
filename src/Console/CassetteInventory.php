<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Config;
use HttpVcr\Exception\CassetteFormatException;

/**
 * What is actually on the tape: which hosts each cassette on disk talks to, and how many
 * interactions each one holds (§3.12).
 *
 * Both `providers` and `tests` need this, and neither can get it from configuration or
 * from test code — a cassette named `checkout` gives away nothing about calling Shopify,
 * and a test that talks to two APIs says so nowhere but in its recording.
 *
 * Which provider a host belongs to is worked out here every time and never stored: that is
 * the same rule the record path follows, so a changed host pattern applies to everything
 * already recorded (§3.12).
 */
final class CassetteInventory
{
    /** @var array<string, array<string, int>> file => host => interaction count */
    private array $hosts = [];

    /** @var list<string> */
    private array $unreadable = [];

    private readonly CassetteEditor $editor;

    public function __construct(
        private readonly Config $config,
        ?string $directory = null,
    ) {
        $this->editor = new CassetteEditor($config, $directory);
    }

    /**
     * Every cassette file in the directory, scope files included — they are separate
     * recordings with separate contents, so nothing is collapsed onto a base name here.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->config->persister()->list($this->config->serializer()->fileExtension()) as $file) {
            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, int> host => how many of this cassette's interactions went to it
     */
    public function hosts(string $file): array
    {
        if (isset($this->hosts[$file])) {
            return $this->hosts[$file];
        }

        $hosts = [];

        try {
            $cassette = $this->editor->read($file);
        } catch (CassetteFormatException $exception) {
            $this->unreadable[] = $exception->getMessage();

            return $this->hosts[$file] = [];
        }

        foreach ($cassette->interactions as $interaction) {
            $host = parse_url($interaction->request->uri, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $host = strtolower($host);
                $hosts[$host] = ($hosts[$host] ?? 0) + 1;
            }
        }

        return $this->hosts[$file] = $hosts;
    }

    /**
     * The name this host answers to: a configured provider's, or — for a host no
     * configuration claimed — the host itself, which is a provider of its own without
     * anyone declaring it (§3.12).
     */
    public function providerOf(string $host): string
    {
        return $this->config->providerFor($host) ?? $host;
    }

    /**
     * @return array<string, array{cassettes: int, interactions: int, hosts: list<string>}>
     *         keyed by provider name, in name order
     */
    public function byProvider(): array
    {
        $providers = [];

        foreach ($this->files() as $file) {
            $seen = [];

            foreach ($this->hosts($file) as $host => $interactions) {
                $name = $this->providerOf($host);

                $providers[$name] ??= ['cassettes' => 0, 'interactions' => 0, 'hosts' => []];
                $providers[$name]['interactions'] += $interactions;

                if (!isset($seen[$name])) {
                    $seen[$name] = true;
                    ++$providers[$name]['cassettes'];
                }

                if (!in_array($host, $providers[$name]['hosts'], true)) {
                    $providers[$name]['hosts'][] = $host;
                }
            }
        }

        ksort($providers);

        return $providers;
    }

    /**
     * Cassettes that could not be read, in the order they were met — reported by the
     * command rather than thrown, so one broken file does not hide the rest of the report.
     *
     * @return list<string>
     */
    public function unreadable(): array
    {
        return $this->unreadable;
    }
}
