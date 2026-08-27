<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Bridge\PHPUnit;

use DateInterval;
use HttpVcr\Ansi;
use HttpVcr\Bridge\PHPUnit\CassetteDirectory;
use HttpVcr\Bridge\PHPUnit\CassetteFactory;
use HttpVcr\Bridge\PHPUnit\DeferredClient;
use HttpVcr\Bridge\PHPUnit\RunWarnings;
use HttpVcr\Bridge\PHPUnit\UseCassette;
use HttpVcr\Config;
use HttpVcr\RecordMode;
use HttpVcr\Stale;
use HttpVcr\VcrClient;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CassetteFactory::class)]
#[CoversClass(UseCassette::class)]
#[CoversClass(CassetteDirectory::class)]
#[CoversClass(RunWarnings::class)]
#[CoversClass(DeferredClient::class)]
final class CassetteFactoryTest extends TestCase
{
    private CassetteFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new CassetteFactory;
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testAMethodDeclaresItsOwnCassette(): void
    {
        $cassette = $this->factory->declaredBy(AttributedTest::class, 'ownCassette');

        self::assertNotNull($cassette);
        self::assertSame('bridge/own', $cassette->name);
        self::assertSame(RecordMode::PlaybackOnly, $cassette->mode);
    }

    public function testAClassLevelAttributeCoversEveryMethodThatDeclaresNothing(): void
    {
        $cassette = $this->factory->declaredBy(AttributedTest::class, 'inheritsFromTheClass');

        self::assertNotNull($cassette);
        self::assertSame('bridge/whole-class', $cassette->name);
    }

    public function testAMethodLevelAttributeReplacesTheClassLevelOneOutright(): void
    {
        $cassette = $this->factory->declaredBy(AttributedTest::class, 'ownCassette');

        self::assertNotNull($cassette);
        self::assertSame(RecordMode::PlaybackOnly, $cassette->mode, 'the class-level mode leaked into the method');
    }

    public function testATestDeclaringNothingAnywhereHasNoCassette(): void
    {
        self::assertNull($this->factory->declaredBy(PlainTest::class, 'notDeclaringAnything'));
        self::assertNull($this->factory->declaredBy(PlainTest::class, 'noSuchMethod'));
    }

    public function testTheCassetteDirectoryIsFoundOnAnAncestorOfTheTestClass(): void
    {
        self::assertSame('/modules/billing/tests/Cassettes', $this->factory->directoryFor(InheritsTheDirectory::class));
        self::assertNull($this->factory->directoryFor(PlainTest::class));
    }

    public function testTheClientIsBuiltWithoutResolvingATransportThatMayNeverBeUsed(): void
    {
        Config::replaceGlobal(Config::create(innerClientFactory: static function (): never {
            throw new LogicException('a replaying test must not resolve the real client');
        }));

        $client = $this->factory->open(new UseCassette('bridge/greeting', RecordMode::PlaybackOnly));

        self::assertInstanceOf(VcrClient::class, $client);
    }

    public function testANamedIntervalOnTheAttributeIsStoredAsTheIntervalItNames(): void
    {
        self::assertSame(7, (new UseCassette('shopify/get-product', staleAfter: Stale::Week))->staleAfter?->d);
        self::assertSame(30, (new UseCassette('x', staleAfter: new DateInterval('P30D')))->staleAfter?->d);
        self::assertNull((new UseCassette('x'))->staleAfter);
    }

    public function testARunWithNothingToSayHasNoSummaryToPrint(): void
    {
        self::assertNull((new RunWarnings)->summary());
    }

    public function testWhatTheCassettesReportedComesOutAsOneBlock(): void
    {
        $warnings = new RunWarnings;

        $warnings->report("http-vcr: tests/Cassettes/payments.json\n  a credential-shaped value\n");
        $warnings->report("http-vcr: tests/Cassettes/checkout.json\n  cassette fully locked\n");

        $summary = (string) $warnings->summary();

        self::assertCount(2, $warnings->all());
        self::assertStringContainsString('2 warnings from this run', $summary);
        self::assertStringContainsString('payments.json', $summary);
        self::assertStringContainsString('checkout.json', $summary);
    }

    /**
     * Only the block's own heading is colored — the warnings inside it arrived already
     * styled by whatever built them, and re-wrapping them here would nest escape codes.
     */
    public function testTheHeadingIsColoredWhereColorIsAvailable(): void
    {
        Ansi::assume(true);

        try {
            $warnings = new RunWarnings;
            $warnings->report("a warning\n");

            $summary = (string) $warnings->summary();
        } finally {
            Ansi::assume(null);
        }

        self::assertSame("\n\033[33mhttp-vcr — 1 warning from this run:\033[0m\n\na warning\n", $summary);
    }
}

#[UseCassette('bridge/whole-class')]
final class AttributedTest
{
    #[UseCassette('bridge/own', RecordMode::PlaybackOnly)]
    public function ownCassette(): void {}

    public function inheritsFromTheClass(): void {}
}

#[CassetteDirectory('/modules/billing/tests/Cassettes')]
abstract class ModuleTestCase {}

final class InheritsTheDirectory extends ModuleTestCase {}

final class PlainTest
{
    public function notDeclaringAnything(): void {}
}
