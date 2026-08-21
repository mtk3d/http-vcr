<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Console\Application;
use HttpVcr\Console\CassetteEditor;
use HttpVcr\Console\LockCommand;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(LockCommand::class)]
#[CoversClass(CassetteEditor::class)]
#[CoversClass(Application::class)]
final class LockCommandTest extends TestCase
{
    use ControlsEnvironment;

    private CassetteDirectory $cassettes;

    protected function setUp(): void
    {
        $this->cassettes = new CassetteDirectory();

        $this->takeOverEnvironment('VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE', 'CI');
        $_ENV['VCR_ALLOW_RECORDING'] = '1';

        Config::replaceGlobal(Config::create(persister: $this->cassettes->persister()));
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        Config::reset();
    }

    public function testItLocksTheOneInteractionItWasPointedAt(): void
    {
        $this->record('shopify/checkout', ['/cart', '/orders']);

        $tester = $this->execute('lock', ['cassette' => 'shopify/checkout', '--interaction' => '2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('interaction #2 locked.', $tester->getDisplay());
        self::assertSame([false, true], $this->locks('shopify/checkout.json'));
    }

    public function testWithoutAPositionItLocksEveryInteractionInTheFile(): void
    {
        $this->record('shopify/checkout', ['/cart', '/orders']);

        $tester = $this->execute('lock', ['cassette' => 'shopify/checkout']);

        self::assertStringContainsString('2 of 2 interactions locked.', $tester->getDisplay());
        self::assertSame([true, true], $this->locks('shopify/checkout.json'));
    }

    public function testUnlockPutsItBack(): void
    {
        $this->record('shopify/checkout', ['/cart']);
        $this->execute('lock', ['cassette' => 'shopify/checkout']);

        $tester = $this->execute('unlock', ['cassette' => 'shopify/checkout']);

        self::assertStringContainsString('1 of 1 interactions unlocked.', $tester->getDisplay());
        self::assertSame([false], $this->locks('shopify/checkout.json'));
    }

    public function testAnAlreadyLockedInteractionIsReportedRatherThanRewritten(): void
    {
        $this->record('shopify/checkout', ['/cart']);
        $this->execute('lock', ['cassette' => 'shopify/checkout']);
        $before = $this->cassettes->read('shopify/checkout.json');

        $tester = $this->execute('lock', ['cassette' => 'shopify/checkout']);

        self::assertStringContainsString('nothing to do, all of it is already locked.', $tester->getDisplay());
        self::assertSame($before, $this->cassettes->read('shopify/checkout.json'));
    }

    public function testABareNameCoversEveryScopeFileAndAScopeNarrowsItToOne(): void
    {
        $this->record('shopify/checkout.2024-01', ['/cart']);
        $this->record('shopify/checkout.2024-04', ['/cart']);

        $this->execute('lock', ['cassette' => 'shopify/checkout']);
        self::assertSame([true], $this->locks('shopify/checkout.2024-01.json'));
        self::assertSame([true], $this->locks('shopify/checkout.2024-04.json'));

        $this->execute('unlock', ['cassette' => 'shopify/checkout', '--scope' => '2024-01']);
        self::assertSame([false], $this->locks('shopify/checkout.2024-01.json'));
        self::assertSame([true], $this->locks('shopify/checkout.2024-04.json'));
    }

    public function testANeighbourWhoseNameMerelyStartsTheSameWayIsLeftAlone(): void
    {
        $this->record('shopify/checkout', ['/cart']);
        $this->record('shopify/checkout-retry', ['/cart']);

        $this->execute('lock', ['cassette' => 'shopify/checkout']);

        self::assertSame([false], $this->locks('shopify/checkout-retry.json'));
    }

    public function testAMissingCassetteIsAFailureRatherThanASilentNoOp(): void
    {
        $tester = $this->execute('lock', ['cassette' => 'shopify/nothing-here']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No cassette named "shopify/nothing-here"', $tester->getDisplay());
    }

    public function testAPositionPastTheEndOfTheCassetteSaysHowManyThereAre(): void
    {
        $this->record('shopify/checkout', ['/cart']);

        $tester = $this->execute('lock', ['cassette' => 'shopify/checkout', '--interaction' => '4']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('has 1 interaction, so there is no #4 to lock.', $tester->getDisplay());
        self::assertSame([false], $this->locks('shopify/checkout.json'));
    }

    /**
     * @param array<string, string> $input
     */
    private function execute(string $command, array $input): CommandTester
    {
        $tester = new CommandTester((new Application())->find($command));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param list<string> $paths
     */
    private function record(string $cassette, array $paths): void
    {
        $inner = new FakeHttpClient();

        foreach ($paths as $path) {
            $inner->willRespond('{"path":"' . $path . '"}');
        }

        $vcr = new VcrClient($inner, $cassette, persister: $this->cassettes->persister());

        foreach ($paths as $path) {
            $vcr->sendRequest(new Request('GET', 'https://shop.example.com' . $path));
        }

        $vcr->close();
    }

    /**
     * @return list<bool>
     */
    private function locks(string $file): array
    {
        $data = json_decode($this->cassettes->read($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['interactions'] ?? null);

        $locks = [];

        foreach ($data['interactions'] as $interaction) {
            self::assertIsArray($interaction);
            $locks[] = ($interaction['locked'] ?? false) === true;
        }

        return $locks;
    }
}
