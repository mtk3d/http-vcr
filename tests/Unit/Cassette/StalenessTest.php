<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Cassette;

use DateInterval;
use DateTimeImmutable;
use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Cassette\Staleness;
use HttpVcr\Clock\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Staleness::class)]
final class StalenessTest extends TestCase
{
    public function testAnInteractionIsStaleOnlyOnceTheThresholdHasBeenPassed(): void
    {
        $cassette = new Cassette([$this->interactionRecordedAt('2026-08-01T12:00:00+00:00')]);
        $week = new DateInterval('P7D');

        self::assertSame([], $this->stale($cassette, $week, '2026-08-08T12:00:00+00:00'));
        self::assertSame([0], $this->stale($cassette, $week, '2026-08-08T12:00:01+00:00'));
    }

    public function testStalenessIsJudgedPerInteractionRatherThanPerFile(): void
    {
        $cassette = new Cassette([
            $this->interactionRecordedAt('2026-01-01T12:00:00+00:00'),
            $this->interactionRecordedAt('2026-08-20T12:00:00+00:00'),
        ]);

        self::assertSame([0], $this->stale($cassette, new DateInterval('P7D'), '2026-08-21T12:00:00+00:00'));
    }

    public function testTheExpiryIsWhereTheReportGetsItsDateFrom(): void
    {
        $staleness = new Staleness(FrozenClock::at('2026-08-21T12:00:00+00:00'));
        $interaction = $this->interactionRecordedAt('2026-08-01T12:00:00+00:00');

        self::assertSame(
            '2026-08-08T12:00:00+00:00',
            $staleness->expiryOf($interaction, new DateInterval('P7D'))->format('c'),
        );
    }

    /**
     * @return list<int>
     */
    private function stale(Cassette $cassette, DateInterval $staleAfter, string $now): array
    {
        return array_keys((new Staleness(FrozenClock::at($now)))->in($cassette, $staleAfter));
    }

    private function interactionRecordedAt(string $recordedAt): Interaction
    {
        return Interaction::recorded(
            new RecordedRequest('GET', 'https://api.example.com/products'),
            new RecordedResponse(200, [], '{}'),
            new DateTimeImmutable($recordedAt),
        );
    }
}
