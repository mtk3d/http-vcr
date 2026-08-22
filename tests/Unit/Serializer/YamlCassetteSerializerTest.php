<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Serializer;

use DateTimeImmutable;
use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\ErrorCategory;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedError;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\Serializer\ArrayCassetteSerializer;
use HttpVcr\Serializer\JsonCassetteSerializer;
use HttpVcr\Serializer\YamlCassetteSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlCassetteSerializer::class)]
#[CoversClass(ArrayCassetteSerializer::class)]
final class YamlCassetteSerializerTest extends TestCase
{
    public function testTheFileExtensionIsWhatTheKeyIsBuiltFrom(): void
    {
        self::assertSame('yaml', (new YamlCassetteSerializer())->fileExtension());
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
                new RecordedResponse(201, ['Set-Cookie' => ['a=1', 'b=2']], "{\n  \"id\": 7\n}\n"),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
                locked: true,
                repeatablePlayback: true,
            ),
            Interaction::failed(
                new RecordedRequest('GET', 'https://example.com/slow'),
                new RecordedError(ErrorCategory::Network, 'Connection timed out after 5000ms', 'GuzzleHttp\Exception\ConnectException'),
                new DateTimeImmutable('2026-08-21T10:05:00+00:00'),
            ),
        ]);

        $serializer = new YamlCassetteSerializer();

        self::assertEquals($cassette, $serializer->deserialize($serializer->serialize($cassette)));
    }

    public function testAValueThatWouldReadBackAsSomethingElseIsQuoted(): void
    {
        $cassette = new Cassette([
            Interaction::recorded(
                new RecordedRequest('GET', 'https://example.com/flags', ['X-Enabled' => ['true'], 'X-Count' => ['42']]),
                new RecordedResponse(200, [], 'null'),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
            ),
        ]);

        $serializer = new YamlCassetteSerializer();

        self::assertEquals($cassette, $serializer->deserialize($serializer->serialize($cassette)));
    }

    public function testABodyWithNewlinesIsWrittenAsABlockRatherThanEscapedOntoOneLine(): void
    {
        $yaml = (new YamlCassetteSerializer())->serialize(new Cassette([
            Interaction::recorded(
                new RecordedRequest('GET', 'https://example.com/page'),
                new RecordedResponse(200, ['Content-Type' => ['text/html']], "<html>\n  <body>Łódź</body>\n</html>"),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
            ),
        ]));

        self::assertStringContainsString("body: |-\n", $yaml);
        self::assertStringContainsString('  <body>Łódź</body>', $yaml);
        self::assertStringNotContainsString('\n', $yaml);
    }

    public function testBinaryContentIsStoredTheSameWayEitherFormatWouldStoreIt(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n" . random_bytes(16);

        $cassette = new Cassette([
            Interaction::recorded(
                new RecordedRequest('GET', 'https://example.com/logo.png'),
                new RecordedResponse(200, ['Content-Type' => ['image/png']], $bytes),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
            ),
        ]);

        $yaml = new YamlCassetteSerializer();
        $restored = $yaml->deserialize($yaml->serialize($cassette));

        self::assertSame($bytes, $restored->interactions[0]->response?->body);
        self::assertStringContainsString('bodyEncoding: base64', $yaml->serialize($cassette));
    }

    public function testACassetteMeansTheSameThingInEitherFormat(): void
    {
        $cassette = new Cassette([
            Interaction::recorded(
                new RecordedRequest('GET', 'https://example.com/products/1', ['Accept' => ['application/json']]),
                new RecordedResponse(200, ['Content-Type' => ['application/json']], '{"title":"T-Shirt"}'),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
            ),
        ]);

        $yaml = new YamlCassetteSerializer();
        $json = new JsonCassetteSerializer();

        self::assertEquals(
            $json->deserialize($json->serialize($cassette)),
            $yaml->deserialize($yaml->serialize($cassette)),
        );
    }

    public function testTextThatIsNotYamlSaysSoRatherThanFailingLater(): void
    {
        $this->expectException(CassetteFormatException::class);
        $this->expectExceptionMessage('is not valid YAML');

        (new YamlCassetteSerializer())->deserialize("interactions:\n  - foo: [unclosed\n");
    }

    public function testAYamlDocumentThatIsNotACassetteSaysWhatIsMissing(): void
    {
        $this->expectException(CassetteFormatException::class);
        $this->expectExceptionMessage('has no schemaVersion');

        (new YamlCassetteSerializer())->deserialize("interactions: []\n");
    }
}
