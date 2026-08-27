<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Console\Application;
use HttpVcr\Console\CassetteInventory;
use HttpVcr\Console\ProvidersCommand;
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

#[CoversClass(ProvidersCommand::class)]
#[CoversClass(CassetteInventory::class)]
final class ProvidersCommandTest extends TestCase
{
    use ControlsEnvironment;

    private CassetteDirectory $cassettes;

    protected function setUp(): void
    {
        Config::reset();

        $this->cassettes = new CassetteDirectory;

        $this->takeOverEnvironment('VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE', 'CI', 'SHOPIFY_API_KEY');
        $_ENV['VCR_ALLOW_RECORDING'] = '1';
        $_ENV['SHOPIFY_API_KEY'] = 'shpat_recording';
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        Config::reset();
    }

    public function testItCountsWhatEachConfiguredProviderOwns(): void
    {
        $this->configure([
            'shopify' => new Provider(hosts: ['*.myshopify.com'], requiresEnv: ['SHOPIFY_API_KEY']),
        ]);
        $this->record('shopify/checkout', ['https://shop.myshopify.com/cart', 'https://shop.myshopify.com/orders']);
        $this->record('shopify/products', ['https://other.myshopify.com/products']);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression(
            '/shopify\s+\*\.myshopify\.com\s+SHOPIFY_API_KEY\s+2 cassettes, 3 interactions/',
            $tester->getDisplay(),
        );
    }

    public function testTheColumnsAreNamed(): void
    {
        $this->configure(['shopify' => new Provider(hosts: ['*.myshopify.com'])]);
        $this->record('shopify/checkout', ['https://shop.myshopify.com/cart']);

        $display = $this->execute()->getDisplay();

        self::assertMatchesRegularExpression('/Provider\s+Hosts\s+Requires\s+Recorded/', $display);
        self::assertMatchesRegularExpression('/Recorded\n\s*shopify/', $display, 'the heading lines up with the rows under it');
    }

    public function testAHostNoConfigurationClaimedIsListedAsImplicit(): void
    {
        $this->configure(['shopify' => new Provider(hosts: ['*.myshopify.com'])]);
        $this->record('shopify/checkout', ['https://shop.myshopify.com/cart']);
        $this->record('billing/charge', ['https://api.stripe.com/v1/charges']);

        $display = $this->execute()->getDisplay();

        self::assertStringContainsString('Implicit', $display);
        self::assertMatchesRegularExpression('/api\.stripe\.com\s+1 cassette, 1 interaction/', $display);
        self::assertStringNotContainsString('shop.myshopify.com  ', $display);
    }

    public function testAConfiguredProviderWithNothingRecordedIsStillListed(): void
    {
        $this->configure(['zendesk' => new Provider(hosts: ['account-a.zendesk.com'])]);

        $display = $this->execute()->getDisplay();

        self::assertMatchesRegularExpression('/zendesk\s+account-a\.zendesk\.com\s+—\s+0 cassettes, 0 interactions/', $display);
    }

    public function testWithoutAnyConfigurationEveryHostIsImplicit(): void
    {
        $this->configure([]);
        $this->record('billing/charge', ['https://api.stripe.com/v1/charges']);

        $display = $this->execute()->getDisplay();

        self::assertStringContainsString('No providers are configured', $display);
        self::assertStringContainsString('api.stripe.com', $display);
    }

    public function testWithNothingRecordedThereIsNothingToHoldTheConfigurationAgainst(): void
    {
        $this->configure(['shopify' => new Provider(hosts: ['*.myshopify.com'])]);

        $display = $this->execute()->getDisplay();

        self::assertStringContainsString('0 cassettes, 0 interactions', $display);
        self::assertStringContainsString('No cassettes have been recorded yet', $display);
    }

    public function testACassetteThatCannotBeReadFailsTheCommand(): void
    {
        $this->configure([]);
        $this->cassettes->write('broken.json', '{"schemaVersion":');

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('broken.json', $tester->getDisplay());
    }

    /**
     * @param  array<string, Provider>  $providers
     */
    private function configure(array $providers): void
    {
        Config::replaceGlobal(Config::create(persister: $this->cassettes->persister(), providers: $providers));
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

    private function execute(): CommandTester
    {
        $tester = new CommandTester((new Application)->find('providers'));
        $tester->execute([]);

        return $tester;
    }
}
