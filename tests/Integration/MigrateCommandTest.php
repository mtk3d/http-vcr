<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Console\Application;
use HttpVcr\Console\MigrateCommand;
use HttpVcr\RecordMode;
use HttpVcr\Serializer\CassetteSerializerInterface;
use HttpVcr\Serializer\JsonCassetteSerializer;
use HttpVcr\Serializer\YamlCassetteSerializer;
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

#[CoversClass(MigrateCommand::class)]
final class MigrateCommandTest extends TestCase
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

    public function testItRewritesEveryCassetteInTheNewFormatAndRemovesTheOld(): void
    {
        $this->configure();
        $this->record('shopify/get-product', new Response(200, ['Content-Type' => 'application/json'], '{"id":42}'));

        $tester = $this->execute(['--to' => 'yaml']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->cassettes->has('shopify/get-product.yaml'));
        self::assertFalse($this->cassettes->has('shopify/get-product.json'));
        self::assertStringContainsString('shopify/get-product', $tester->getDisplay());
    }

    public function testTheMigratedCassetteStillReplaysWhatWasRecorded(): void
    {
        $this->configure();
        $this->record('shopify/get-product', new Response(201, ['Content-Type' => 'application/json'], '{"id":42}'));

        $this->execute(['--to' => 'yaml']);

        $vcr = new VcrClient(
            new FakeHttpClient,
            'shopify/get-product',
            RecordMode::PlaybackOnly,
            persister: $this->cassettes->persister(),
            serializer: new YamlCassetteSerializer,
        );

        $response = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"id":42}', (string) $response->getBody());
    }

    public function testABodyInASidecarSurvivesTheMigration(): void
    {
        $this->configure(inlineBodyLimit: 32);
        $this->record('billing/charge', new Response(200, ['Content-Type' => 'text/plain'], str_repeat('padding ', 20)));

        $this->execute(['--to' => 'yaml']);

        $vcr = new VcrClient(
            new FakeHttpClient,
            'billing/charge',
            RecordMode::PlaybackOnly,
            persister: $this->cassettes->persister(),
            serializer: new YamlCassetteSerializer,
        );

        $response = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));

        self::assertSame(str_repeat('padding ', 20), (string) $response->getBody());
    }

    public function testItRefusesToOverwriteACassetteThatAlreadyExistsInTheNewFormat(): void
    {
        $this->configure();
        $this->record('shopify/get-product', new Response(200, [], '{"id":42}'));
        $this->cassettes->write('shopify/get-product.yaml', "schemaVersion: 1\ninteractions: []\n");

        $tester = $this->execute(['--to' => 'yaml']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertTrue($this->cassettes->has('shopify/get-product.json'));
        self::assertSame("schemaVersion: 1\ninteractions: []\n", $this->cassettes->read('shopify/get-product.yaml'));
    }

    public function testDryRunReportsWhatItWouldDoAndTouchesNothing(): void
    {
        $this->configure();
        $this->record('shopify/get-product', new Response(200, [], '{"id":42}'));

        $tester = $this->execute(['--to' => 'yaml', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->cassettes->has('shopify/get-product.json'));
        self::assertFalse($this->cassettes->has('shopify/get-product.yaml'));
        self::assertStringContainsString('shopify/get-product', $tester->getDisplay());
    }

    public function testACassetteThatCannotBeReadFailsTheCommandAndIsLeftAlone(): void
    {
        $this->configure();
        $this->cassettes->write('broken.json', '{"schemaVersion":');

        $tester = $this->execute(['--to' => 'yaml']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('broken.json', $tester->getDisplay());
        self::assertTrue($this->cassettes->has('broken.json'));
        self::assertFalse($this->cassettes->has('broken.yaml'));
    }

    public function testItMigratesBackToJsonToo(): void
    {
        $this->configure(serializer: new YamlCassetteSerializer);
        $this->record('shopify/get-product', new Response(200, [], '{"id":42}'));

        $tester = $this->execute(['--to' => 'json']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->cassettes->has('shopify/get-product.json'));
        self::assertFalse($this->cassettes->has('shopify/get-product.yaml'));
    }

    public function testAFormatItDoesNotKnowIsRefusedRatherThanGuessed(): void
    {
        $this->configure();

        $tester = $this->execute(['--to' => 'xml']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('xml', $tester->getDisplay());
    }

    public function testNothingToMigrateIsReportedRatherThanLookingLikeSuccessfulWork(): void
    {
        $this->configure();

        $tester = $this->execute(['--to' => 'yaml']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No cassettes', $tester->getDisplay());
    }

    private function configure(?int $inlineBodyLimit = null, ?CassetteSerializerInterface $serializer = null): void
    {
        Config::replaceGlobal(Config::create(
            persister: $this->cassettes->persister(),
            serializer: $serializer ?? new JsonCassetteSerializer,
            inlineBodyLimit: $inlineBodyLimit,
            testDirectories: [$this->project->path],
        ));
    }

    private function record(string $cassette, ResponseInterface $response): void
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

        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->close();
    }

    /**
     * @param  array<string, bool|string>  $input
     */
    private function execute(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application)->find('migrate'));
        $tester->execute($input);

        return $tester;
    }
}
