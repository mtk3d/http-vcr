<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Ansi;
use HttpVcr\Config;
use HttpVcr\Console\Application;
use HttpVcr\Console\ScanSecretsCommand;
use HttpVcr\Provider;
use HttpVcr\RecordMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ScanSecretsCommand::class)]
final class ScanSecretsCommandTest extends TestCase
{
    use ControlsEnvironment;

    private CassetteDirectory $cassettes;

    /** An empty stand-in for the consuming project's `tests/`, so the sweep stays in this test's world. */
    private CassetteDirectory $project;

    protected function setUp(): void
    {
        Config::reset();

        $this->cassettes = new CassetteDirectory;
        $this->project = new CassetteDirectory;

        $this->takeOverEnvironment('VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE', 'CI');
        $_ENV['VCR_ALLOW_RECORDING'] = '1';
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        $this->project->remove();
        Config::reset();
    }

    public function testItNamesTheCassetteTheInteractionAndWhereTheValueSits(): void
    {
        $this->configure();
        $this->record('shopify/checkout', new Response(200, [], '{"refresh_token":"8f3c2a9b1d4e6f70a2b5c8d1e4f70a2b"}'));

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('shopify/checkout.json', $tester->getDisplay());
        self::assertStringContainsString('#1', $tester->getDisplay());
        self::assertStringContainsString('response.body (/refresh_token)', $tester->getDisplay());
    }

    public function testTheExcerptNeverPrintsTheWholeValue(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'));

        $display = $this->execute()->getDisplay();

        self::assertStringContainsString('sk_live_…', $display);
        self::assertStringNotContainsString('sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR', $display);
    }

    public function testFindingsOnlyFailTheCommandWhenAskedTo(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'));

        self::assertSame(Command::SUCCESS, $this->execute()->getStatusCode());
        self::assertSame(Command::FAILURE, $this->execute(['--fail-on-findings' => true])->getStatusCode());
    }

    public function testARedactedCassetteIsNotAFinding(): void
    {
        $this->configure();
        $this->record(
            'billing/charge',
            new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'),
            static function (VcrClient $vcr): void {
                $vcr->redactJsonField('/api_key');
            },
        );

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No credential-shaped values', $tester->getDisplay());
    }

    public function testAValueAConfiguredRuleRedactsIsFoundEvenWhenItLooksLikeNothing(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"greeting":"letmein"}'));

        // The rule arrives after the recording — the cassette this command exists for.
        Config::reset();
        $this->configure(['<COMPANY_PROXY_TOKEN>' => static fn (): string => 'letmein']);

        $display = $this->execute()->getDisplay();

        self::assertStringContainsString('<COMPANY_PROXY_TOKEN>', $display);
        self::assertStringContainsString('billing/charge.json', $display);
    }

    public function testNamingACassetteSweepsThatOneAndLeavesTheRestAlone(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'));
        $this->record('shopify/checkout', new Response(200, [], '{"refresh_token":"8f3c2a9b1d4e6f70a2b5c8d1e4f70a2b"}'));

        $display = $this->execute(['cassette' => 'billing/charge'])->getDisplay();

        self::assertStringContainsString('billing/charge.json', $display);
        self::assertStringNotContainsString('shopify/checkout.json', $display);
        self::assertStringContainsString('1 of 1 cassettes', $display, 'the cassettes not named were never scanned');
    }

    public function testNamingACassetteThatIsNotThereIsAFailureRatherThanACleanSweep(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'));

        $tester = $this->execute(['cassette' => 'billing/refund']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No cassette named "billing/refund"', $tester->getDisplay());
    }

    public function testAProviderNarrowsTheSweepToWhatBelongsToIt(): void
    {
        $this->configure(providers: [
            'shopify' => new Provider(['*.myshopify.com']),
            'stripe' => new Provider(['api.stripe.com']),
        ]);
        $this->record(
            'shopify/checkout',
            new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'),
            uri: 'https://shop.myshopify.com/cart',
        );
        $this->record(
            'billing/charge',
            new Response(200, [], '{"refresh_token":"8f3c2a9b1d4e6f70a2b5c8d1e4f70a2b"}'),
            uri: 'https://api.stripe.com/v1/charges',
        );

        $display = $this->execute(['--provider' => 'stripe'])->getDisplay();

        self::assertStringContainsString('billing/charge.json', $display);
        self::assertStringNotContainsString('shopify/checkout.json', $display);
    }

    public function testAProviderNothingAnswersToIsRefusedRatherThanReportingACleanSweep(): void
    {
        $this->configure(providers: ['shopify' => new Provider(['*.myshopify.com'])]);
        $this->record('shopify/checkout', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'), uri: 'https://shop.myshopify.com/cart');

        $tester = $this->execute(['--provider' => 'shopfiy']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('shopfiy', $tester->getDisplay());
        self::assertStringContainsString('shopify', $tester->getDisplay());
    }

    public function testAConfirmedFindingIsReplacedEverywhereItAppearsInTheCassette(): void
    {
        $this->configure();
        $this->record(
            'billing/charge',
            new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR","echo":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'),
        );

        $tester = $this->execute(['--redact' => true], ['yes', '']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR', $this->contentsOf('billing/charge'));
        self::assertStringContainsString('<REDACTED-API-KEY>', $this->contentsOf('billing/charge'));
        self::assertSame(2, substr_count($this->contentsOf('billing/charge'), '<REDACTED-API-KEY>'));
    }

    public function testTheOriginalIsSaidToBeGoneForGood(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'));

        $display = $this->execute(['--redact' => true], ['yes', ''])->getDisplay();

        self::assertStringContainsString('one-way', $display);
    }

    public function testAPlaceholderOfYourOwnIsTakenOverTheOneOffered(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'));

        $this->execute(['--redact' => true], ['yes', '<STRIPE_KEY>']);

        self::assertStringContainsString('<STRIPE_KEY>', $this->contentsOf('billing/charge'));
        self::assertStringNotContainsString('<REDACTED-API-KEY>', $this->contentsOf('billing/charge'));
    }

    public function testDecliningLeavesTheCassetteExactlyAsItWas(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'));
        $before = $this->contentsOf('billing/charge');

        $tester = $this->execute(['--redact' => true], ['no']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($before, $this->contentsOf('billing/charge'));
    }

    /**
     * Hiding a value is a decision, and a decision needs somebody to make it — a pipeline
     * silently rewriting cassettes is the opposite of what this is for.
     */
    public function testRedactingWithNobodyToAskIsRefused(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'));
        $before = $this->contentsOf('billing/charge');

        $tester = new CommandTester((new Application)->find('scan-secrets'));
        $tester->execute(['--redact' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('needs a terminal', $tester->getDisplay());
        self::assertSame($before, $this->contentsOf('billing/charge'));
    }

    /**
     * A value in the request is matched on, so replacing it in the cassette alone leaves a
     * recording that no live request can match any more.
     */
    public function testAValueInTheRequestComesWithTheWarningThatMatchingDependsOnIt(): void
    {
        $this->configure();
        $this->record(
            'billing/charge',
            new Response(200, [], '{"ok":true}'),
            uri: 'https://api.stripe.com/v1/charges?api_key=sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR',
        );

        $display = $this->execute(['--redact' => true], ['yes', ''])->getDisplay();

        self::assertStringContainsString('sits in the request', $display);
        self::assertStringContainsString('http-vcr.php', $display);
    }

    /**
     * The same three colours the warning printed after a recording uses, so one finding
     * looks like itself wherever it surfaces (§7 decision 66).
     */
    public function testOnATerminalTheFindingCarriesTheSameColorsTheRunTimeWarningDoes(): void
    {
        $this->configure();
        $this->record('billing/charge', new Response(200, [], '{"api_key":"sk_live_51H8sT2eZvKYlo2CabcdefghijklmnopQR"}'));

        Ansi::assume(true);

        try {
            $display = $this->execute()->getDisplay();
        } finally {
            Ansi::assume(null);
        }

        self::assertStringContainsString("\033[1mresponse.body (/api_key)\033[0m", $display);
        self::assertStringContainsString("\033[31m\"sk_live_…\"\033[0m", $display);
    }

    public function testASidecarBodyIsScannedLikeAnyOtherBody(): void
    {
        $this->configure(inlineBodyLimit: 32);
        $this->record('billing/charge', new Response(200, ['Content-Type' => 'text/plain'], str_repeat('padding ', 20).'Bearer 8f3c2a9b1d4e6f70a2b5c8d1'));

        self::assertTrue($this->hasSidecar(), 'the body was supposed to land in a sidecar file');

        $display = $this->execute()->getDisplay();

        self::assertStringContainsString('billing/charge.json', $display);
        self::assertStringContainsString('Bearer …', $display);
    }

    public function testACassetteThatCannotBeReadFailsTheCommand(): void
    {
        $this->configure();
        $this->cassettes->write('broken.json', '{"schemaVersion":');

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('broken.json', $tester->getDisplay());
    }

    /**
     * @param  array<string, callable(): mixed>  $redact
     * @param  array<string, Provider>  $providers
     */
    private function configure(array $redact = [], ?int $inlineBodyLimit = null, array $providers = []): void
    {
        Config::replaceGlobal(Config::create(
            persister: $this->cassettes->persister(),
            testDirectories: [$this->project->path],
            inlineBodyLimit: $inlineBodyLimit,
            redact: $redact,
            providers: $providers,
        ));
    }

    /**
     * @param  (callable(VcrClient): void)|null  $configure
     */
    private function record(
        string $cassette,
        ResponseInterface $response,
        ?callable $configure = null,
        string $uri = 'https://shop.example.com/cart',
    ): void {
        $inner = new FakeHttpClient;
        $inner->willRespond($response);

        $vcr = new VcrClient(
            $inner,
            $cassette,
            RecordMode::ExtendCassette,
            persister: $this->cassettes->persister(),
            warn: static fn (string $warning): null => null,
        );

        if ($configure !== null) {
            $configure($vcr);
        }

        $vcr->sendRequest(new Request('GET', $uri));
        $vcr->close();
    }

    private function hasSidecar(): bool
    {
        return glob($this->cassettes->path.'/billing/*.bin') !== [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $answers  what the wizard's questions are answered with
     */
    private function execute(array $input = [], array $answers = []): CommandTester
    {
        $tester = new CommandTester((new Application)->find('scan-secrets'));

        if ($answers !== []) {
            $tester->setInputs($answers);
        }

        $tester->execute($input);

        return $tester;
    }

    private function contentsOf(string $cassette): string
    {
        return (string) file_get_contents($this->cassettes->path.'/'.$cassette.'.json');
    }
}
