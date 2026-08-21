<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Cassette;

use Closure;
use DateTimeImmutable;
use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Clock\FrozenClock;
use HttpVcr\Environment;
use HttpVcr\Matching\CompositeMatcher;
use HttpVcr\Matching\MethodMatcher;
use HttpVcr\Matching\QueryStringMatcher;
use HttpVcr\Matching\UriMatcher;
use HttpVcr\RecordMode;
use HttpVcr\SecretScanner;
use HttpVcr\Serializer\JsonCassetteSerializer;
use HttpVcr\StrictMode;
use HttpVcr\Tests\Support\InMemoryCassettePersister;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CassetteManager::class)]
final class CassetteManagerTest extends TestCase
{
    public function testAReplayingSessionTakesNoLockAtAll(): void
    {
        $persister = $this->persisterHolding([$this->interaction('https://example.com/a')]);

        $manager = $this->manager($persister);
        $manager->play(new RecordedRequest('GET', 'https://example.com/a'));
        $manager->close();

        self::assertSame([], $persister->locked);
        self::assertSame([], $persister->writes);
    }

    public function testARecordingSessionHoldsTheLockUntilItIsClosed(): void
    {
        $persister = new InMemoryCassettePersister();

        $manager = $this->manager($persister);
        $manager->record(
            new RecordedRequest('GET', 'https://example.com/a'),
            new RecordedResponse(200, [], '{}'),
        );

        self::assertSame(['session.cassette-lock'], $persister->locked);
        self::assertSame([], $persister->unlocked);

        $manager->close();

        self::assertSame(['session.cassette-lock'], $persister->unlocked);
    }

    public function testTheLockFileIsNotTheCassetteFile(): void
    {
        $persister = new InMemoryCassettePersister();

        $manager = $this->manager($persister);
        $manager->record(new RecordedRequest('GET', 'https://example.com/a'), new RecordedResponse(200));

        self::assertSame(['session.cassette-lock'], $persister->locked);
        self::assertSame(['session.json'], $persister->writes);
    }

    public function testACassetteThatAppearedWhileTheLockWasBeingTakenIsReplayedNotRecordedTwice(): void
    {
        $persister = new InMemoryCassettePersister();
        $persister->whileLocking(function (InMemoryCassettePersister $persister): void {
            $persister->write('session.json', (new JsonCassetteSerializer())->serialize(
                new Cassette([$this->interaction('https://example.com/a')]),
            ));
        });

        $manager = $this->manager($persister);
        $interaction = $manager->play(new RecordedRequest('GET', 'https://example.com/a'));

        self::assertNotNull($interaction);
        self::assertFalse($manager->isRecording());
        self::assertTrue($manager->cassetteExists());
        self::assertSame(['session.cassette-lock'], $persister->unlocked);
    }

    public function testRecordingTimestampsComeFromTheInjectedClock(): void
    {
        $manager = $this->manager(new InMemoryCassettePersister());

        $interaction = $manager->record(
            new RecordedRequest('GET', 'https://example.com/a'),
            new RecordedResponse(200),
        );

        self::assertNotNull($interaction);
        self::assertSame('2026-08-21T10:00:00+00:00', $interaction->recordedAt->format('c'));
    }

    public function testASessionThatRecordedASecretWarnsWhenItCloses(): void
    {
        $warnings = [];
        $manager = $this->manager(
            new InMemoryCassettePersister(),
            scanner: new SecretScanner(),
            warn: static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );

        $manager->record(
            new RecordedRequest('POST', 'https://example.com/token'),
            new RecordedResponse(200, [], '{"key":"tk_live_9f8e7d6c5b4a3210FEDCBA"}'),
        );
        $manager->close();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('response.body (/key)', $warnings[0]);
    }

    public function testTheWarningIsPrintedOnceEvenIfTheSessionIsClosedTwice(): void
    {
        $warnings = [];
        $manager = $this->manager(
            new InMemoryCassettePersister(),
            scanner: new SecretScanner(),
            warn: static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );

        $manager->record(
            new RecordedRequest('POST', 'https://example.com/token'),
            new RecordedResponse(200, [], '{"key":"tk_live_9f8e7d6c5b4a3210FEDCBA"}'),
        );
        $manager->close();
        $manager->close();

        self::assertCount(1, $warnings);
    }

    /**
     * Warning about content already looked at and knowingly accepted would make the warning
     * worthless within a fortnight.
     */
    public function testASessionThatOnlyReplayedSaysNothing(): void
    {
        $warnings = [];
        $persister = $this->persisterHolding([
            Interaction::recorded(
                new RecordedRequest('GET', 'https://example.com/a'),
                new RecordedResponse(200, [], '{"key":"tk_live_9f8e7d6c5b4a3210FEDCBA"}'),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
            ),
        ]);

        $manager = $this->manager(
            $persister,
            scanner: new SecretScanner(),
            warn: static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );

        $manager->play(new RecordedRequest('GET', 'https://example.com/a'));
        $manager->close();

        self::assertSame([], $warnings);
    }

    public function testTheScanIsSkippedEntirelyWhenTheProjectTurnedItOff(): void
    {
        $warnings = [];
        $manager = $this->manager(
            new InMemoryCassettePersister(),
            warn: static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );

        $manager->record(
            new RecordedRequest('POST', 'https://example.com/token'),
            new RecordedResponse(200, [], '{"key":"tk_live_9f8e7d6c5b4a3210FEDCBA"}'),
        );
        $manager->close();

        self::assertSame([], $warnings);
    }

    public function testAFullyLockedCassetteUnderForcedRecordingReportsThatNothingHappened(): void
    {
        $persister = $this->persisterHolding([
            $this->interaction('https://example.com/a')->withLocked(true),
        ]);

        $manager = $this->manager($persister, ['VCR_ERASE_TAPE' => 'all', 'VCR_ALLOW_RECORDING' => '1']);
        $manager->play(new RecordedRequest('GET', 'https://example.com/a'));

        self::assertTrue($manager->eraseTapeHadNoEffect());
        self::assertSame([], $persister->writes);
    }

    public function testAPartiallyErasedCassetteIsNotReportedAsHavingHadNoEffect(): void
    {
        $persister = $this->persisterHolding([
            $this->interaction('https://example.com/a')->withLocked(true),
            $this->interaction('https://example.com/b'),
        ]);

        $manager = $this->manager($persister, ['VCR_ERASE_TAPE' => 'all', 'VCR_ALLOW_RECORDING' => '1']);

        self::assertFalse($manager->eraseTapeHadNoEffect());
        self::assertSame(1, $manager->interactionCount());
        self::assertSame(['session.json'], $persister->writes, 'the truncated file is written when the session opens');
    }

    public function testACassetteLockedFromCodeIsSparedEntirelyWithoutTouchingTheData(): void
    {
        $persister = $this->persisterHolding([$this->interaction('https://example.com/a')]);

        $manager = $this->manager(
            $persister,
            ['VCR_ERASE_TAPE' => 'all', 'VCR_ALLOW_RECORDING' => '1'],
            locked: true,
        );

        self::assertSame(1, $manager->interactionCount());
        self::assertTrue($manager->eraseTapeHadNoEffect());
    }

    public function testMismatchesAreKeyedByPositionInTheCassetteCountingFromOne(): void
    {
        $persister = $this->persisterHolding([
            $this->interaction('https://example.com/a'),
            $this->interaction('https://example.com/b'),
        ]);

        $manager = $this->manager($persister);
        $manager->play(new RecordedRequest('GET', 'https://example.com/a'));

        $mismatches = $manager->mismatches(new RecordedRequest('GET', 'https://example.com/c'));

        self::assertSame([2], array_keys($mismatches));
        self::assertSame('UriMatcher: expected path "/b"', $mismatches[2]->describe());
    }

    /**
     * @param array<string, string> $environment
     */
    private function manager(
        InMemoryCassettePersister $persister,
        array $environment = [],
        bool $locked = false,
        ?SecretScanner $scanner = null,
        ?Closure $warn = null,
    ): CassetteManager {
        return new CassetteManager(
            'session',
            null,
            $persister,
            new JsonCassetteSerializer(),
            CompositeMatcher::of([new MethodMatcher(), new UriMatcher(), new QueryStringMatcher()]),
            FrozenClock::at('2026-08-21T10:00:00+00:00'),
            new Environment($environment),
            RecordMode::RecordIfAbsent,
            StrictMode::None,
            null,
            false,
            $locked,
            scanner: $scanner,
            warn: $warn,
        );
    }

    /**
     * @param list<Interaction> $interactions
     */
    private function persisterHolding(array $interactions): InMemoryCassettePersister
    {
        $persister = new InMemoryCassettePersister();
        $persister->write('session.json', (new JsonCassetteSerializer())->serialize(new Cassette($interactions)));
        $persister->writes = [];

        return $persister;
    }

    private function interaction(string $uri): Interaction
    {
        return Interaction::recorded(
            new RecordedRequest('GET', $uri),
            new RecordedResponse(200, [], '{}'),
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        );
    }
}
