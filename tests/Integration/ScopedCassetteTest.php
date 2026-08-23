<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Cassette\CassetteSession;
use HttpVcr\Config;
use HttpVcr\Exception\CassetteNotFoundException;
use HttpVcr\Exception\RecordingNotAllowedException;
use HttpVcr\Exception\StrictModeViolationException;
use HttpVcr\RecordMode;
use HttpVcr\Scope\CallbackScopeResolver;
use HttpVcr\Scope\CassetteScopeResolverInterface;
use HttpVcr\Scope\RegexUrlScopeResolver;
use HttpVcr\StrictMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use InvalidArgumentException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(VcrClient::class)]
#[CoversClass(CassetteSession::class)]
#[CoversClass(CassetteManager::class)]
#[CoversClass(RegexUrlScopeResolver::class)]
#[CoversClass(CallbackScopeResolver::class)]
final class ScopedCassetteTest extends TestCase
{
    use ControlsEnvironment;

    private const SHOPIFY_VERSION = '#/admin/api/(?<scope>\d{4}-\d{2})/#';

    private CassetteDirectory $cassettes;

    protected function setUp(): void
    {
        $this->cassettes = new CassetteDirectory;

        $this->takeOverEnvironment('VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE', 'CI');
        $_ENV['VCR_ALLOW_RECORDING'] = '1';
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        Config::reset();
    }

    public function testTheScopeBecomesPartOfTheFileName(): void
    {
        $this->record('/admin/api/2024-01/products/1.json', '{"id":1}');

        self::assertTrue($this->cassettes->has('shopify/get-product.2024-01.json'));
        self::assertFalse($this->cassettes->has('shopify/get-product.json'));
    }

    public function testTwoScopesOfOneCassetteAreSeparateFiles(): void
    {
        $this->record('/admin/api/2024-01/products/1.json', '{"version":"2024-01"}');
        $this->record('/admin/api/2024-04/products/1.json', '{"version":"2024-04"}');

        self::assertSame(
            '{"version":"2024-01"}',
            $this->cassettes->cassette('shopify/get-product.2024-01.json')->responseBody(0),
        );
        self::assertSame(
            '{"version":"2024-04"}',
            $this->cassettes->cassette('shopify/get-product.2024-04.json')->responseBody(0),
        );
    }

    public function testAScopeAlreadyOnDiskReplaysWithoutARealRequest(): void
    {
        $this->record('/admin/api/2024-01/products/1.json', '{"id":1}');

        $inner = new FakeHttpClient;
        $response = $this->client($inner)
            ->sendRequest(new Request('GET', 'https://shop.example.com/admin/api/2024-01/products/1.json'));

        self::assertSame(0, $inner->sentCount());
        self::assertSame('{"id":1}', (string) $response->getBody());
    }

    public function testPlaybackOnlyListsTheScopesThatDoExist(): void
    {
        $this->record('/admin/api/2024-01/products/1.json', '{"id":1}');

        $this->expectException(CassetteNotFoundException::class);
        $this->expectExceptionMessage('No cassette recorded for scope "2024-04" (base: shopify/get-product).');
        $this->expectExceptionMessage('Existing scopes: 2024-01.');
        $this->expectExceptionMessage('Mode is PlaybackOnly, which never records');

        $this->client(mode: RecordMode::PlaybackOnly)
            ->sendRequest(new Request('GET', 'https://shop.example.com/admin/api/2024-04/products/1.json'));
    }

    public function testABlockedRecordingBlamesTheVariableAndStillListsTheScopes(): void
    {
        $this->record('/admin/api/2024-01/products/1.json', '{"id":1}');
        $_ENV['VCR_ALLOW_RECORDING'] = '0';

        $this->expectException(RecordingNotAllowedException::class);
        $this->expectExceptionMessage('Cannot record cassette "shopify/get-product" (scope "2024-04")');
        $this->expectExceptionMessage('recording is disabled by VCR_ALLOW_RECORDING=0');
        $this->expectExceptionMessage('Existing scopes: 2024-01.');

        $this->client()->sendRequest(new Request('GET', 'https://shop.example.com/admin/api/2024-04/products/1.json'));
    }

    public function testAnUnscopedRequestKeepsUsingTheCassettesOwnFile(): void
    {
        $this->record('/oauth/token', '{"token":"t"}');

        self::assertTrue($this->cassettes->has('shopify/get-product.json'));
    }

    public function testStrictModeIsCheckedPerScopeFileRatherThanOverOnePool(): void
    {
        $this->record('/admin/api/2024-01/products/1.json', '{"id":1}');
        $this->record('/admin/api/2024-01/products/2.json', '{"id":2}');
        $this->record('/admin/api/2024-04/products/1.json', '{"id":1}');
        $this->record('/admin/api/2024-04/products/2.json', '{"id":2}');

        $vcr = $this->client(strictMode: StrictMode::AllPlayed);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/admin/api/2024-01/products/1.json'));
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/admin/api/2024-01/products/2.json'));
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/admin/api/2024-04/products/1.json'));

        try {
            $vcr->close();
            self::fail('Expected the half-replayed scope file to be reported.');
        } catch (StrictModeViolationException $exception) {
            self::assertStringContainsString('get-product.2024-04.json', $exception->getMessage());
            self::assertStringContainsString('#2  GET https://shop.example.com/admin/api/2024-04/products/2.json', $exception->getMessage());
            self::assertStringNotContainsString('2024-01', $exception->getMessage());
        }
    }

    public function testACallbackResolverCanKeyOnAnythingAtAll(): void
    {
        $resolver = new CallbackScopeResolver(
            static fn (RequestInterface $request): ?string => $request->getHeaderLine('X-Api-Version') ?: null,
        );

        $vcr = $this->client((new FakeHttpClient)->willRespond('{"id":1}'), $resolver);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1', ['X-Api-Version' => 'v3']));

        self::assertTrue($this->cassettes->has('shopify/get-product.v3.json'));
    }

    public function testAScopeIsSanitizedIntoASinglePathSegment(): void
    {
        $vcr = $this->client(
            (new FakeHttpClient)->willRespond('{"id":1}'),
            new CallbackScopeResolver(static fn (): string => 'v3/beta rc:1'),
        );
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1'));

        self::assertTrue($this->cassettes->has('shopify/get-product.v3_beta_rc_1.json'));
    }

    public function testAScopeThatCannotBeAFileNameIsRefusedRatherThanMangled(): void
    {
        $vcr = $this->client(
            new FakeHttpClient,
            new CallbackScopeResolver(static fn (): string => '../../etc'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The scope resolver returned "../../etc" for cassette "shopify/get-product"');

        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1'));
    }

    private function record(string $path, string $body): void
    {
        // ExtendCassette so that two calls can build up one scope file, which is what the
        // per-file checks below need.
        $vcr = $this->client((new FakeHttpClient)->willRespond($body), mode: RecordMode::ExtendCassette);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com'.$path));
        $vcr->close();
    }

    private function client(
        ?FakeHttpClient $inner = null,
        ?CassetteScopeResolverInterface $resolver = null,
        RecordMode $mode = RecordMode::RecordIfAbsent,
        StrictMode $strictMode = StrictMode::None,
    ): VcrClient {
        return new VcrClient(
            $inner ?? new FakeHttpClient,
            'shopify/get-product',
            $mode,
            strictMode: $strictMode,
            scopeResolver: $resolver ?? new RegexUrlScopeResolver(self::SHOPIFY_VERSION),
            persister: $this->cassettes->persister(),
        );
    }
}
