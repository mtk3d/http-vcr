<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Hook;

use DateTimeImmutable;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Hook\HookRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HookRegistry::class)]
final class HookRegistryTest extends TestCase
{
    private static function interaction(string $body = 'original'): Interaction
    {
        return Interaction::recorded(
            new RecordedRequest('GET', 'https://example.com/products'),
            new RecordedResponse(200, [], $body),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    public function testRunsRecordHooksInRegistrationOrder(): void
    {
        $registry = new HookRegistry();
        $registry->addBeforeRecord(static fn (Interaction $i): Interaction => $i->withResponse(
            $i->response?->withBody('first') ?? new RecordedResponse(200, [], 'first'),
        ));
        $registry->addBeforeRecord(static fn (Interaction $i): Interaction => $i->withResponse(
            $i->response?->withBody(($i->response->body) . '-second') ?? new RecordedResponse(200, [], 'second'),
        ));

        self::assertSame('first-second', $registry->beforeRecord(self::interaction())?->response?->body);
    }

    public function testARecordHookMayRefuseTheInteraction(): void
    {
        $registry = new HookRegistry();
        $registry->addBeforeRecord(static fn (): ?Interaction => null);

        self::assertNull($registry->beforeRecord(self::interaction()));
    }

    public function testTheHooksAfterARefusalNeverRun(): void
    {
        $registry = new HookRegistry();
        $registry->addBeforeRecord(static fn (): ?Interaction => null);
        $registry->addBeforeRecord(static function (): ?Interaction {
            throw new LogicException('This hook should never have been called.');
        });

        self::assertNull($registry->beforeRecord(self::interaction()));
    }

    public function testRunsPlaybackHooksInRegistrationOrder(): void
    {
        $registry = new HookRegistry();
        $registry->addBeforePlayback(static fn (Interaction $i): Interaction => $i->withRequest(
            $i->request->withHeader('X-Order', 'first'),
        ));
        $registry->addBeforePlayback(static fn (Interaction $i): Interaction => $i->withRequest(
            $i->request->withHeader('X-Order', $i->request->header('X-Order')[0] . '-second'),
        ));

        self::assertSame(['first-second'], $registry->beforePlayback(self::interaction())->request->header('X-Order'));
    }

    public function testAPlaybackHookMayNotRefuseTheInteraction(): void
    {
        $registry = new HookRegistry();
        /** @phpstan-ignore argument.type (the point of the test is what happens when it is wrong) */
        $registry->addBeforePlayback(static fn (): ?Interaction => null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/beforePlayback hook returned null/');

        $registry->beforePlayback(self::interaction());
    }

    public function testAnInteractionPassesThroughAnEmptyRegistryUnchanged(): void
    {
        $registry = new HookRegistry();
        $interaction = self::interaction();

        self::assertSame($interaction, $registry->beforeRecord($interaction));
        self::assertSame($interaction, $registry->beforePlayback($interaction));
    }
}
