<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Serializer;

use DateTimeImmutable;
use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\Serializer\JsonCassetteSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonCassetteSerializer::class)]
#[CoversClass(CassetteFormatException::class)]
final class JsonCassetteSerializerTest extends TestCase
{
    public function testTheFileExtensionIsWhatTheKeyIsBuiltFrom(): void
    {
        self::assertSame('json', (new JsonCassetteSerializer)->fileExtension());
    }

    public function testWritesTheDocumentedShape(): void
    {
        $cassette = new Cassette([
            Interaction::recorded(
                new RecordedRequest('GET', 'https://example.com/products/1', ['Accept' => ['application/json']]),
                new RecordedResponse(200, ['Content-Type' => ['application/json']], '{"title":"T-Shirt"}'),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
            ),
        ]);

        self::assertSame(
            <<<'JSON'
                {
                    "schemaVersion": 1,
                    "interactions": [
                        {
                            "request": {
                                "method": "GET",
                                "uri": "https://example.com/products/1",
                                "headers": {
                                    "Accept": [
                                        "application/json"
                                    ]
                                },
                                "body": ""
                            },
                            "response": {
                                "status": 200,
                                "headers": {
                                    "Content-Type": [
                                        "application/json"
                                    ]
                                },
                                "body": "{\"title\":\"T-Shirt\"}"
                            },
                            "outcome": "success",
                            "recordedAt": "2026-08-21T10:00:00+00:00"
                        }
                    ]
                }

                JSON,
            (new JsonCassetteSerializer)->serialize($cassette),
        );
    }

    public function testFieldsCarryingTheirDefaultAreLeftOutAndReadBackAsThatDefault(): void
    {
        $serializer = new JsonCassetteSerializer;
        $json = $serializer->serialize(new Cassette([$this->interaction()]));

        self::assertStringNotContainsString('locked', $json);
        self::assertStringNotContainsString('repeatablePlayback', $json);

        $interaction = $serializer->deserialize($json)->interactions[0];
        self::assertFalse($interaction->locked);
        self::assertFalse($interaction->repeatablePlayback);
    }

    public function testKeepsEverythingThroughARoundTrip(): void
    {
        $cassette = new Cassette([
            Interaction::recorded(
                new RecordedRequest(
                    'POST',
                    'https://example.com/orders?tag=a&tag=b',
                    ['Content-Type' => ['application/json'], 'X-Multi' => ['one', 'two']],
                    '{"amount":100}',
                ),
                new RecordedResponse(201, ['Set-Cookie' => ['a=1', 'b=2']], '{"id":7}'),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
                locked: true,
                repeatablePlayback: true,
            ),
            $this->interaction(),
        ]);

        $serializer = new JsonCassetteSerializer;
        $restored = $serializer->deserialize($serializer->serialize($cassette));

        self::assertEquals($cassette, $restored);
    }

    public function testSlashesAndUnicodeAreLeftReadableInTheFile(): void
    {
        $json = (new JsonCassetteSerializer)->serialize(new Cassette([
            Interaction::recorded(
                new RecordedRequest('GET', 'https://example.com/a/b'),
                new RecordedResponse(200, [], '{"name":"Łódź"}'),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
            ),
        ]));

        self::assertStringContainsString('https://example.com/a/b', $json);
        self::assertStringContainsString('Łódź', $json);
    }

    public function testANewerSchemaVersionSaysToUpgradeRatherThanGuessingAtTheShape(): void
    {
        $this->expectException(CassetteFormatException::class);
        $this->expectExceptionMessage('schema version 2, where this installation of http-vcr writes and reads 1');

        (new JsonCassetteSerializer)->deserialize('{"schemaVersion": 2, "interactions": []}');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableCassettes(): iterable
    {
        yield 'not JSON' => ['{oops', 'is not valid JSON'];
        yield 'not an object' => ['[]', 'is not a JSON object'];
        yield 'no version' => ['{"interactions": []}', 'has no schemaVersion'];
        yield 'interactions not a list' => [
            '{"schemaVersion": 1, "interactions": {"first": {}}}',
            'has no list of interactions',
        ];
        yield 'interaction without a request' => [
            '{"schemaVersion": 1, "interactions": [{"response": {"status": 200}}]}',
            'has an interaction #1 without a readable request',
        ];
        yield 'interaction without a response' => [
            '{"schemaVersion": 1, "interactions": [{"request": {"method": "GET", "uri": "https://example.com"}}]}',
            'has an interaction #1 without a readable response',
        ];
        yield 'interaction without a timestamp' => [
            '{"schemaVersion": 1, "interactions": [{"request": {"method": "GET", "uri": "https://example.com"}, "response": {"status": 200}}]}',
            'has no recordedAt in interaction #1',
        ];
        yield 'non-string header value' => [
            '{"schemaVersion": 1, "interactions": [{"request": {"method": "GET", "uri": "https://example.com", "headers": {"X": [1]}}, "response": {"status": 200}, "recordedAt": "2026-08-21T10:00:00+00:00"}]}',
            'has a non-string value for header "X" in interaction #1',
        ];
    }

    #[DataProvider('unreadableCassettes')]
    public function testAnUnreadableCassetteSaysWhatIsWrongWithIt(string $json, string $expected): void
    {
        $this->expectException(CassetteFormatException::class);
        $this->expectExceptionMessage($expected);

        (new JsonCassetteSerializer)->deserialize($json);
    }

    public function testTheProblemCanBeAttributedToAFileByWhoeverReadIt(): void
    {
        $problem = CassetteFormatException::malformed('is not valid JSON (Syntax error)');

        $located = $problem->in('tests/Cassettes/shopify/get-product.json');

        self::assertSame(
            'Cassette tests/Cassettes/shopify/get-product.json: is not valid JSON (Syntax error)',
            $located->getMessage(),
        );
        self::assertSame($problem, $located->getPrevious());
    }

    private function interaction(): Interaction
    {
        return Interaction::recorded(
            new RecordedRequest('GET', 'https://example.com/products/1'),
            new RecordedResponse(200, [], '{}'),
            new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
        );
    }
}
