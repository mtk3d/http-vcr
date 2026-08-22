<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Import;

use DateTimeImmutable;
use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\ErrorCategory;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\Outcome;
use HttpVcr\Cassette\RecordedError;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Config;
use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\Import\HarCassetteExporter;
use HttpVcr\Import\HarCassetteImporter;
use HttpVcr\Tests\Support\CassetteDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HarCassetteImporter::class)]
#[CoversClass(HarCassetteExporter::class)]
final class HarCassetteTest extends TestCase
{
    public function testAnEntryBecomesAnInteraction(): void
    {
        $cassette = (new HarCassetteImporter())->convert($this->har([
            [
                'startedDateTime' => '2026-08-21T10:00:00.000Z',
                'request' => [
                    'method' => 'POST',
                    'url' => 'https://example.com/orders?tag=a',
                    'headers' => [
                        ['name' => 'Content-Type', 'value' => 'application/json'],
                        ['name' => 'X-Multi', 'value' => 'one'],
                        ['name' => 'X-Multi', 'value' => 'two'],
                    ],
                    'postData' => ['mimeType' => 'application/json', 'text' => '{"amount":100}'],
                ],
                'response' => [
                    'status' => 201,
                    'headers' => [['name' => 'Content-Type', 'value' => 'application/json']],
                    'content' => ['size' => 8, 'mimeType' => 'application/json', 'text' => '{"id":7}'],
                ],
            ],
        ]));

        self::assertCount(1, $cassette->interactions);

        $interaction = $cassette->interactions[0];

        self::assertSame('POST', $interaction->request->method);
        self::assertSame('https://example.com/orders?tag=a', $interaction->request->uri);
        self::assertSame(['one', 'two'], $interaction->request->headers['X-Multi']);
        self::assertSame('{"amount":100}', $interaction->request->body);
        self::assertSame('2026-08-21T10:00:00+00:00', $interaction->recordedAt->format('c'));

        $response = $interaction->response;

        self::assertNotNull($response);
        self::assertSame(201, $response->status);
        self::assertSame('{"id":7}', $response->body);
    }

    public function testABase64EntryComesBackAsTheBytesItStandsFor(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n" . str_repeat("\x00\xff", 8);

        $cassette = (new HarCassetteImporter())->convert($this->har([
            [
                'startedDateTime' => '2026-08-21T10:00:00.000Z',
                'request' => ['method' => 'GET', 'url' => 'https://example.com/logo.png', 'headers' => []],
                'response' => [
                    'status' => 200,
                    'headers' => [['name' => 'Content-Type', 'value' => 'image/png']],
                    'content' => ['size' => 24, 'mimeType' => 'image/png', 'text' => base64_encode($bytes), 'encoding' => 'base64'],
                ],
            ],
        ]));

        $response = $cassette->interactions[0]->response;

        self::assertNotNull($response);
        self::assertSame($bytes, $response->body);
        self::assertSame('base64', $response->bodyEncoding);
    }

    public function testARequestTheBrowserSawFailIsImportedAsARecordedFailure(): void
    {
        $cassette = (new HarCassetteImporter())->convert($this->har([
            [
                'startedDateTime' => '2026-08-21T10:00:00.000Z',
                'request' => ['method' => 'GET', 'url' => 'https://example.com/slow', 'headers' => []],
                'response' => ['status' => 0, 'headers' => [], 'content' => ['size' => 0, 'mimeType' => '']],
                '_error' => 'net::ERR_CONNECTION_TIMED_OUT',
            ],
        ]));

        $interaction = $cassette->interactions[0];

        self::assertSame(Outcome::Error, $interaction->outcome);

        $error = $interaction->error;

        self::assertNotNull($error);
        self::assertSame(ErrorCategory::Network, $error->category);
        self::assertSame('net::ERR_CONNECTION_TIMED_OUT', $error->message);
    }

    public function testWhatBothFormatsCanSaySurvivesTheRoundTrip(): void
    {
        $cassette = new Cassette([
            Interaction::recorded(
                new RecordedRequest(
                    'POST',
                    'https://example.com/orders?tag=a&tag=b',
                    ['Content-Type' => ['application/json'], 'X-Multi' => ['one', 'two']],
                    '{"amount":100}',
                ),
                new RecordedResponse(201, ['Content-Type' => ['application/json']], '{"id":7}'),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
            ),
            Interaction::failed(
                new RecordedRequest('GET', 'https://example.com/slow'),
                new RecordedError(ErrorCategory::Network, 'Connection timed out', ''),
                new DateTimeImmutable('2026-08-21T10:05:00+00:00'),
            ),
        ]);

        $restored = (new HarCassetteImporter())->convert((new HarCassetteExporter())->toHar($cassette));

        self::assertEquals($cassette, $restored);
    }

    public function testTheExportIsAHarFileWithWhatAToolReadingItExpects(): void
    {
        $har = json_decode((new HarCassetteExporter())->toHar(new Cassette([
            Interaction::recorded(
                new RecordedRequest('GET', 'https://example.com/products?page=2'),
                new RecordedResponse(200, ['Content-Type' => ['application/json']], '{"ok":true}'),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
            ),
        ])), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($har);

        $log = $har['log'] ?? null;

        self::assertIsArray($log);
        self::assertSame('1.2', $log['version'] ?? null);
        self::assertIsArray($log['creator'] ?? null);
        self::assertSame('http-vcr', $log['creator']['name'] ?? null);
        self::assertIsArray($log['entries'] ?? null);

        $entry = $log['entries'][0] ?? null;

        self::assertIsArray($entry);
        self::assertIsArray($entry['request'] ?? null);
        self::assertSame([['name' => 'page', 'value' => '2']], $entry['request']['queryString'] ?? null);
        self::assertIsArray($entry['response'] ?? null);
        self::assertIsArray($entry['response']['content'] ?? null);
        self::assertSame('{"ok":true}', $entry['response']['content']['text'] ?? null);
    }

    public function testTextThatIsNotAHarFileSaysSo(): void
    {
        $this->expectException(CassetteFormatException::class);
        $this->expectExceptionMessage('is not a HAR file');

        (new HarCassetteImporter())->convert('{"something":"else"}');
    }

    public function testTextThatIsNotJsonAtAllSaysThatInstead(): void
    {
        $this->expectException(CassetteFormatException::class);
        $this->expectExceptionMessage('is not valid JSON');

        (new HarCassetteImporter())->convert('<html></html>');
    }

    public function testAHarFileBecomesACassetteWhereTheProjectKeepsThem(): void
    {
        $cassettes = new CassetteDirectory();
        $config = Config::create(persister: $cassettes->persister());

        $file = $cassettes->path . '/captured.har';
        $cassettes->write('captured.har', $this->har([
            [
                'startedDateTime' => '2026-08-21T10:00:00.000Z',
                'request' => ['method' => 'GET', 'url' => 'https://example.com/products', 'headers' => []],
                'response' => [
                    'status' => 200,
                    'headers' => [['name' => 'Content-Type', 'value' => 'application/json']],
                    'content' => ['size' => 11, 'mimeType' => 'application/json', 'text' => '{"ok":true}'],
                ],
            ],
        ]));

        try {
            (new HarCassetteImporter($config))->import($file, 'shopify/get-product');

            self::assertTrue($cassettes->has('shopify/get-product.json'));

            (new HarCassetteExporter($config))->export('shopify/get-product', $cassettes->path . '/out.har');

            self::assertSame(
                '{"ok":true}',
                (new HarCassetteImporter($config))->convert($cassettes->read('out.har'))
                    ->interactions[0]->response?->body,
            );
        } finally {
            $cassettes->remove();
        }
    }

    public function testAHarFileThatIsNotThereSaysSoRatherThanImportingNothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new HarCassetteImporter(Config::create()))->import('/nowhere/captured.har', 'shopify/get-product');
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function har(array $entries): string
    {
        return json_encode([
            'log' => [
                'version' => '1.2',
                'creator' => ['name' => 'WebInspector', 'version' => '537.36'],
                'entries' => $entries,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
