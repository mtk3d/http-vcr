<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use DateTimeImmutable;
use HttpVcr\Clock\FrozenClock;
use HttpVcr\Config;
use HttpVcr\Console\Application;
use HttpVcr\Console\StaleCommand;
use HttpVcr\RecordMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(StaleCommand::class)]
final class StaleCommandTest extends TestCase
{
    use ControlsEnvironment;

    private CassetteDirectory $cassettes;

    /** A throwaway directory standing in for the consuming project's `tests/`. */
    private CassetteDirectory $project;

    protected function setUp(): void
    {
        Config::reset();

        $this->cassettes = new CassetteDirectory;
        $this->project = new CassetteDirectory;

        $this->takeOverEnvironment('VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE', 'CI');
        $_ENV['VCR_ALLOW_RECORDING'] = '1';

        $this->configure('2026-08-22 12:00:00');
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        $this->project->remove();
        Config::reset();
    }

    public function testItNamesTheInteractionsPastTheThresholdTheirTestDeclared(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout', "new \\DateInterval('P7D')");
        $this->record('shopify/checkout', ['/cart', '/orders'], ['2026-08-01 09:00:00', '2026-08-21 09:00:00']);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('shopify/checkout.json', $tester->getDisplay());
        self::assertStringContainsString('#1 GET https://shop.example.com/cart', $tester->getDisplay());
        self::assertStringNotContainsString('#2', $tester->getDisplay());
        self::assertStringContainsString('1 interaction in 1 cassette is past its threshold.', $tester->getDisplay());
    }

    public function testACassetteNoTestDeclaresAThresholdForIsSkipped(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout', null);
        $this->record('shopify/checkout', ['/cart'], ['2020-01-01 09:00:00']);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing has a threshold to check.', $tester->getDisplay());
    }

    public function testEveryScopeFileOfOneDeclaredNameIsCheckedAndNamedSeparately(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout', "new \\DateInterval('P7D')");
        $this->record('shopify/checkout.2024-01', ['/cart'], ['2026-08-01 09:00:00']);
        $this->record('shopify/checkout.2024-04', ['/cart'], ['2026-08-21 09:00:00']);

        $display = $this->execute()->getDisplay();

        self::assertStringContainsString('shopify/checkout.2024-01.json', $display);
        self::assertStringNotContainsString('shopify/checkout.2024-04.json', $display);
    }

    public function testTwoTestsDisagreeingAboutOneCassetteAreReportedRatherThanResolved(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout', "new \\DateInterval('P7D')");
        $this->declare('CheckoutAgainTest', 'shopify/checkout', "new \\DateInterval('P30D')");
        $this->record('shopify/checkout', ['/cart'], ['2026-08-01 09:00:00']);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('shopify/checkout: conflicting thresholds declared', $tester->getDisplay());
        self::assertStringNotContainsString('#1 GET', $tester->getDisplay());
    }

    public function testACassetteThatWasNeverRecordedIsNotAFinding(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout', "new \\DateInterval('P7D')");

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing is past its threshold.', $tester->getDisplay());
    }

    public function testAThresholdTheScanCouldNotReadIsReportedInsteadOfPassedOver(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout', 'self::INTERVAL');
        $this->record('shopify/checkout', ['/cart'], ['2020-01-01 09:00:00']);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('CheckoutTest.php', $tester->getDisplay());
        self::assertStringContainsString('staleAfter', $tester->getDisplay());
    }

    public function testACassetteThatCannotBeReadFailsTheCommand(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout', "new \\DateInterval('P7D')");
        $this->cassettes->write('shopify/checkout.json', '{"schemaVersion":');

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('shopify/checkout.json', $tester->getDisplay());
    }

    private function configure(string $now): void
    {
        Config::replaceGlobal(Config::create(
            persister: $this->cassettes->persister(),
            clock: new FrozenClock(new DateTimeImmutable($now)),
            testDirectories: [$this->project->path],
        ));
    }

    private function declare(string $class, string $cassette, ?string $staleAfter): void
    {
        $argument = $staleAfter === null ? '' : ', staleAfter: '.$staleAfter;

        $this->project->write($class.'.php', <<<PHP
            <?php
            namespace App\\Tests;

            use HttpVcr\\Bridge\\PHPUnit\\UseCassette;

            final class {$class} extends \\PHPUnit\\Framework\\TestCase
            {
                #[UseCassette('{$cassette}'{$argument})]
                public function testItRuns(): void {}
            }
            PHP);
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $recordedAt  one moment per path
     */
    private function record(string $cassette, array $paths, array $recordedAt): void
    {
        foreach ($paths as $index => $path) {
            $inner = new FakeHttpClient;
            $inner->willRespond('{"path":"'.$path.'"}');

            $vcr = new VcrClient(
                $inner,
                $cassette,
                RecordMode::ExtendCassette,
                persister: $this->cassettes->persister(),
                clock: new FrozenClock(new DateTimeImmutable($recordedAt[$index])),
            );

            $vcr->sendRequest(new Request('GET', 'https://shop.example.com'.$path));
            $vcr->close();
        }
    }

    private function execute(): CommandTester
    {
        $tester = new CommandTester((new Application)->find('stale'));
        $tester->execute([]);

        return $tester;
    }
}
