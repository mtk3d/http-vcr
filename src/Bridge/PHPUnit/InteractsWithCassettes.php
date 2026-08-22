<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use DateInterval;
use HttpVcr\RecordMode;
use HttpVcr\StrictMode;
use HttpVcr\VcrClient;
use LogicException;
use PHPUnit\Framework\Attributes\After;

/**
 * Two methods for two situations, and the first is used *with* `#[UseCassette]` rather
 * than instead of it (§3.12).
 */
trait InteractsWithCassettes
{
    /**
     * The client the attribute built for this test: the PSR-18 client to hand the code
     * under test, and what per-test redaction and hooks are registered on.
     *
     * Still unfrozen when `setUp()` runs — the extension's hook fires before it — so
     * `setUp()` or the opening lines of the test are the place for `redact()` and friends.
     */
    protected function vcrClient(): VcrClient
    {
        $client = CurrentCassetteSession::client();

        if ($client === null) {
            throw new LogicException(
                'No cassette is open for this test. Either the test has no #[UseCassette] attribute, or '
                . "the extension is not registered — PHPUnit has no auto-discovery for extensions, so\n\n"
                . "    <extensions>\n"
                . "        <bootstrap class=\"HttpVcr\\Bridge\\PHPUnit\\Extension\"/>\n"
                . "    </extensions>\n\n"
                . 'has to be in phpunit.xml. Without it the attribute is decoration and the test makes '
                . 'real requests.',
            );
        }

        return $client;
    }

    /**
     * A cassette session around a closure, with no attribute involved: for PHPUnit 9 and
     * older, which has no Extension API; for a test that needs two different cassettes; and
     * for tests not written in PHPUnit at all.
     *
     * @template T
     *
     * @param callable(VcrClient): T $body
     * @param list<string>           $requiresEnv
     *
     * @return T
     */
    protected function useCassette(
        string $name,
        callable $body,
        RecordMode $mode = RecordMode::RecordIfAbsent,
        ?StrictMode $strictMode = null,
        ?DateInterval $staleAfter = null,
        array $requiresEnv = [],
        bool $locked = false,
    ): mixed {
        $previous = CurrentCassetteSession::client();

        $client = (new CassetteFactory())->open(
            new UseCassette($name, $mode, $strictMode, $staleAfter, $requiresEnv, $locked),
            $this->cassetteDirectory(),
        );

        CurrentCassetteSession::begin($client);

        try {
            $result = $body($client);
        } finally {
            // Ends this cassette either way, then puts back whatever was open around it —
            // an attribute's session, when a test opens a second cassette inside one.
            CurrentCassetteSession::end();

            if ($previous !== null) {
                CurrentCassetteSession::begin($previous);
            }
        }

        return $result;
    }

    /**
     * Closes the session here rather than leaving it to the extension's own after-test
     * hook, because this runs inside the test: a strict-mode assertion raised here fails
     * the test that broke it, while an exception from an event subscriber is only ever a
     * warning from the runner (§3.6).
     */
    #[After]
    protected function closeCassetteSession(): void
    {
        CurrentCassetteSession::end();
    }

    private function cassetteDirectory(): ?string
    {
        return (new CassetteFactory())->directoryFor(static::class);
    }
}
