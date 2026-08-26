<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Console\Application;
use HttpVcr\Console\ScanSecretsCommand;
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
     */
    private function configure(array $redact = [], ?int $inlineBodyLimit = null): void
    {
        Config::replaceGlobal(Config::create(
            persister: $this->cassettes->persister(),
            testDirectories: [$this->project->path],
            inlineBodyLimit: $inlineBodyLimit,
            redact: $redact,
        ));
    }

    /**
     * @param  (callable(VcrClient): void)|null  $configure
     */
    private function record(string $cassette, ResponseInterface $response, ?callable $configure = null): void
    {
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

        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->close();
    }

    private function hasSidecar(): bool
    {
        return glob($this->cassettes->path.'/billing/*.bin') !== [];
    }

    /**
     * @param  array<string, bool>  $input
     */
    private function execute(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application)->find('scan-secrets'));
        $tester->execute($input);

        return $tester;
    }
}
