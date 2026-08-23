<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Console\Application;
use HttpVcr\Console\TestsCommand;
use HttpVcr\Provider;
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

#[CoversClass(TestsCommand::class)]
final class TestsCommandTest extends TestCase
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

        $this->configure(['shopify' => new Provider(hosts: ['*.myshopify.com'])]);
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        $this->project->remove();
        Config::reset();
    }

    public function testATestIsListedForEveryProviderItsCassetteActuallyTouches(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout');
        $this->record('shopify/checkout', ['https://shop.myshopify.com/cart', 'https://api.stripe.com/v1/charges']);

        $tester = $this->execute(['--provider' => 'shopify']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('App\Tests\CheckoutTest::testItRuns', $tester->getDisplay());
        self::assertStringContainsString('shopify/checkout', $tester->getDisplay());

        $byHost = $this->execute(['--provider' => 'api.stripe.com']);

        self::assertStringContainsString('App\Tests\CheckoutTest::testItRuns', $byHost->getDisplay());
    }

    public function testATestWhoseCassetteNeverTouchesTheProviderIsLeftOut(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout');
        $this->declare('BillingTest', 'billing/charge');
        $this->record('shopify/checkout', ['https://shop.myshopify.com/cart']);
        $this->record('billing/charge', ['https://api.stripe.com/v1/charges']);

        $display = $this->execute(['--provider' => 'shopify'])->getDisplay();

        self::assertStringContainsString('CheckoutTest', $display);
        self::assertStringNotContainsString('BillingTest', $display);
    }

    public function testFilterOnlyPrintsARegexThatMatchesTheTestsAndNothingElse(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout');
        $this->declare('BillingTest', 'billing/charge');
        $this->record('shopify/checkout', ['https://shop.myshopify.com/cart']);
        $this->record('billing/charge', ['https://api.stripe.com/v1/charges']);

        $regex = trim($this->execute(['--provider' => 'shopify', '--filter-only' => true])->getDisplay());

        self::assertSame(1, preg_match($regex, 'App\Tests\CheckoutTest::testItRuns'));
        self::assertSame(1, preg_match($regex, 'App\Tests\CheckoutTest::testItRuns#3'), 'a data set of the same test');
        self::assertSame(0, preg_match($regex, 'App\Tests\BillingTest::testItRuns'));
        self::assertSame(0, preg_match($regex, 'App\Tests\CheckoutTest::testItRunsElsewhere'));
    }

    public function testATestWhoseCassetteWasNeverRecordedCannotBeFoundAndTheReportSaysSo(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout');

        $tester = $this->execute(['--provider' => 'shopify']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No test touches shopify', $tester->getDisplay());
        self::assertStringContainsString('recorded', $tester->getDisplay());
    }

    public function testFilterOnlyWithoutAMatchPrintsARegexThatMatchesNothing(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout');

        $regex = trim($this->execute(['--provider' => 'shopify', '--filter-only' => true])->getDisplay());

        self::assertNotSame('', $regex);
        self::assertSame(0, preg_match($regex, 'App\Tests\CheckoutTest::testItRuns'));
    }

    public function testAnUnknownProviderNameListsBothTheConfiguredNamesAndTheHostsSeen(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout');
        $this->record('shopify/checkout', ['https://shop.myshopify.com/cart', 'https://api.stripe.com/v1/charges']);

        $tester = $this->execute(['--provider' => 'shoppify']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('shopify', $tester->getDisplay());
        self::assertStringContainsString('api.stripe.com', $tester->getDisplay());
    }

    public function testAHostAConfiguredProviderClaimedIsNoLongerAddressableByItself(): void
    {
        $this->declare('CheckoutTest', 'shopify/checkout');
        $this->record('shopify/checkout', ['https://shop.myshopify.com/cart']);

        $tester = $this->execute(['--provider' => 'shop.myshopify.com']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testWithoutAProviderItSaysWhatItNeeds(): void
    {
        $tester = $this->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--provider', $tester->getDisplay());
    }

    /**
     * @param  array<string, Provider>  $providers
     */
    private function configure(array $providers): void
    {
        Config::replaceGlobal(Config::create(
            persister: $this->cassettes->persister(),
            providers: $providers,
            testDirectories: [$this->project->path],
        ));
    }

    private function declare(string $class, string $cassette): void
    {
        $this->project->write($class.'.php', <<<PHP
            <?php
            namespace App\\Tests;

            use HttpVcr\\Bridge\\PHPUnit\\UseCassette;

            final class {$class} extends \\PHPUnit\\Framework\\TestCase
            {
                #[UseCassette('{$cassette}')]
                public function testItRuns(): void {}

                public function testItRunsElsewhere(): void {}
            }
            PHP);
    }

    /**
     * @param  list<string>  $urls
     */
    private function record(string $cassette, array $urls): void
    {
        $inner = new FakeHttpClient;

        foreach ($urls as $url) {
            $inner->willRespond('{}');
        }

        $vcr = new VcrClient($inner, $cassette, RecordMode::ExtendCassette, persister: $this->cassettes->persister());

        foreach ($urls as $url) {
            $vcr->sendRequest(new Request('GET', $url));
        }

        $vcr->close();
    }

    /**
     * @param  array<string, bool|string>  $input
     */
    private function execute(array $input): CommandTester
    {
        $tester = new CommandTester((new Application)->find('tests'));
        $tester->execute($input);

        return $tester;
    }
}
