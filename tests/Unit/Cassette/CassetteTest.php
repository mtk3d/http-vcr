<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Cassette;

use DateTimeImmutable;
use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cassette::class)]
#[CoversClass(Interaction::class)]
final class CassetteTest extends TestCase
{
    public function testANewCassetteCarriesTheCurrentSchemaVersionAndNoInteractions(): void
    {
        $cassette = new Cassette;

        self::assertSame(Cassette::CURRENT_SCHEMA_VERSION, $cassette->schemaVersion);
        self::assertTrue($cassette->isEmpty());
    }

    public function testAppendingAnInteractionKeepsRecordingOrderAndTheSchemaVersion(): void
    {
        $cassette = new Cassette([], 1);

        $grown = $cassette
            ->withInteraction($this->interaction('https://example.com/first'))
            ->withInteraction($this->interaction('https://example.com/second'));

        self::assertTrue($cassette->isEmpty());
        self::assertSame(1, $grown->schemaVersion);
        self::assertSame(
            ['https://example.com/first', 'https://example.com/second'],
            array_map(static fn (Interaction $i): string => $i->request->uri, $grown->interactions),
        );
    }

    public function testInteractionWithersReplaceOneFieldAtATime(): void
    {
        $interaction = $this->interaction('https://example.com/a');

        $changed = $interaction
            ->withResponse(new RecordedResponse(500))
            ->withLocked(true)
            ->withRepeatablePlayback(true);

        self::assertNotNull($interaction->response);
        self::assertNotNull($changed->response);
        self::assertSame(200, $interaction->response->status);
        self::assertFalse($interaction->locked);
        self::assertSame(500, $changed->response->status);
        self::assertTrue($changed->locked);
        self::assertTrue($changed->repeatablePlayback);
        self::assertSame($interaction->request, $changed->request);
        self::assertSame($interaction->recordedAt, $changed->recordedAt);
    }

    private function interaction(string $uri): Interaction
    {
        return Interaction::recorded(
            new RecordedRequest('GET', $uri),
            new RecordedResponse(200),
            new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
        );
    }
}
