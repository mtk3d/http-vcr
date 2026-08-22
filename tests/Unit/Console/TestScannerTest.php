<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Console;

use HttpVcr\Console\CassetteDeclaration;
use HttpVcr\Console\ScannedTests;
use HttpVcr\Console\TestScanner;
use HttpVcr\RecordMode;
use HttpVcr\StrictMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestScanner::class)]
#[CoversClass(ScannedTests::class)]
#[CoversClass(CassetteDeclaration::class)]
final class TestScannerTest extends TestCase
{
    private CassetteDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new CassetteDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testAMethodAttributeBecomesOneDeclaration(): void
    {
        $this->file('ShopifyTest.php', <<<'PHP'
            <?php
            namespace App\Tests;

            use HttpVcr\Bridge\PHPUnit\UseCassette;

            final class ShopifyTest extends \PHPUnit\Framework\TestCase
            {
                #[UseCassette('shopify/get-product')]
                public function testItReadsAProduct(): void {}
            }
            PHP);

        $declarations = $this->scan()->declarations;

        self::assertCount(1, $declarations);
        self::assertSame('App\Tests\ShopifyTest', $declarations[0]->class);
        self::assertSame('testItReadsAProduct', $declarations[0]->method);
        self::assertSame('shopify/get-product', $declarations[0]->declared->name);
        self::assertNull($declarations[0]->directory);
    }

    public function testAClassAttributeCoversEveryTestMethodAndAMethodOneReplacesIt(): void
    {
        $this->file('WholeClassTest.php', <<<'PHP'
            <?php
            namespace App\Tests;

            use HttpVcr\Bridge\PHPUnit\UseCassette;
            use PHPUnit\Framework\Attributes\Test;

            #[UseCassette('shared')]
            final class WholeClassTest extends \PHPUnit\Framework\TestCase
            {
                public function testFirst(): void {}

                #[Test]
                public function secondOne(): void {}

                #[UseCassette('its-own')]
                public function testThird(): void {}

                public function helper(): void {}

                protected function testNotPublic(): void {}
            }
            PHP);

        $declarations = $this->scan()->declarations;

        self::assertSame(
            ['testFirst' => 'shared', 'secondOne' => 'shared', 'testThird' => 'its-own'],
            $this->named($declarations),
        );
    }

    public function testEveryConstructorArgumentTheScanCanReadEndsUpOnTheAttribute(): void
    {
        $this->file('ArgumentsTest.php', <<<'PHP'
            <?php
            namespace App\Tests;

            use HttpVcr\Bridge\PHPUnit\UseCassette;
            use HttpVcr\RecordMode;
            use HttpVcr\StrictMode;

            final class ArgumentsTest extends \PHPUnit\Framework\TestCase
            {
                #[UseCassette(
                    'billing/charge',
                    RecordMode::PlaybackOnly,
                    strictMode: StrictMode::AllPlayed,
                    staleAfter: new \DateInterval('P7D'),
                    requiresEnv: ['STRIPE_KEY'],
                    locked: true,
                )]
                public function testItCharges(): void {}
            }
            PHP);

        $declared = $this->scan()->declarations[0]->declared;

        self::assertSame('billing/charge', $declared->name);
        self::assertSame(RecordMode::PlaybackOnly, $declared->mode);
        self::assertSame(StrictMode::AllPlayed, $declared->strictMode);
        self::assertSame(7, $declared->staleAfter?->d);
        self::assertSame(['STRIPE_KEY'], $declared->requiresEnv);
        self::assertTrue($declared->locked);
    }

    public function testACassetteDirectoryIsInheritedAndItsDirConstantResolvesWhereItIsWritten(): void
    {
        $this->file('Module/ModuleTestCase.php', <<<'PHP'
            <?php
            namespace App\Tests\Module;

            use HttpVcr\Bridge\PHPUnit\CassetteDirectory;

            #[CassetteDirectory(__DIR__ . '/Cassettes')]
            abstract class ModuleTestCase extends \PHPUnit\Framework\TestCase
            {
            }
            PHP);

        $this->file('Module/InvoiceTest.php', <<<'PHP'
            <?php
            namespace App\Tests\Module;

            use HttpVcr\Bridge\PHPUnit\UseCassette;

            final class InvoiceTest extends ModuleTestCase
            {
                #[UseCassette('invoice')]
                public function testItInvoices(): void {}
            }
            PHP);

        $declarations = $this->scan()->declarations;

        self::assertCount(1, $declarations);
        self::assertSame($this->directory->path . '/Module/Cassettes', $declarations[0]->directory);
    }

    public function testAnAbstractParentDeclaresForItsSubclassesAndNotForItself(): void
    {
        $this->file('BaseTest.php', <<<'PHP'
            <?php
            namespace App\Tests;

            use HttpVcr\Bridge\PHPUnit\UseCassette;

            #[UseCassette('inherited')]
            abstract class BaseTest extends \PHPUnit\Framework\TestCase
            {
                public function testFromTheParent(): void {}
            }
            PHP);

        $this->file('ChildTest.php', <<<'PHP'
            <?php
            namespace App\Tests;

            final class ChildTest extends BaseTest
            {
                public function testFromTheChild(): void {}
            }
            PHP);

        $declarations = $this->scan()->declarations;

        self::assertSame(['App\Tests\ChildTest'], array_values(array_unique(
            array_map(static fn (CassetteDeclaration $declaration): string => $declaration->class, $declarations),
        )));
        self::assertSame(
            ['testFromTheChild' => 'inherited', 'testFromTheParent' => 'inherited'],
            $this->named($declarations),
        );
    }

    public function testAnArgumentTheScanCannotReadIsReportedAndTheRestSurvives(): void
    {
        $this->file('ComputedTest.php', <<<'PHP'
            <?php
            namespace App\Tests;

            use HttpVcr\Bridge\PHPUnit\UseCassette;

            final class ComputedTest extends \PHPUnit\Framework\TestCase
            {
                #[UseCassette('billing/charge', staleAfter: self::INTERVAL)]
                public function testItCharges(): void {}
            }
            PHP);

        $scanned = $this->scan();

        self::assertCount(1, $scanned->declarations);
        self::assertNull($scanned->declarations[0]->declared->staleAfter);
        self::assertCount(1, $scanned->unanalyzed);
        self::assertStringContainsString('ComputedTest.php', $scanned->unanalyzed[0]);
        self::assertStringContainsString('staleAfter', $scanned->unanalyzed[0]);
    }

    public function testACassetteNameTheScanCannotReadLeavesNoDeclarationAtAll(): void
    {
        $this->file('NamelessTest.php', <<<'PHP'
            <?php
            namespace App\Tests;

            use HttpVcr\Bridge\PHPUnit\UseCassette;

            final class NamelessTest extends \PHPUnit\Framework\TestCase
            {
                #[UseCassette(self::NAME)]
                public function testItRuns(): void {}
            }
            PHP);

        $scanned = $this->scan();

        self::assertSame([], $scanned->declarations);
        self::assertCount(1, $scanned->unanalyzed);
        self::assertStringContainsString('name', $scanned->unanalyzed[0]);
    }

    public function testAFileThatDoesNotParseIsReportedRatherThanEndingTheScan(): void
    {
        $this->file('BrokenTest.php', '<?php class Broken { public function');
        $this->file('FineTest.php', <<<'PHP'
            <?php
            namespace App\Tests;

            use HttpVcr\Bridge\PHPUnit\UseCassette;

            final class FineTest extends \PHPUnit\Framework\TestCase
            {
                #[UseCassette('fine')]
                public function testItRuns(): void {}
            }
            PHP);

        $scanned = $this->scan();

        self::assertCount(1, $scanned->declarations);
        self::assertCount(1, $scanned->unanalyzed);
        self::assertStringContainsString('BrokenTest.php', $scanned->unanalyzed[0]);
    }

    public function testADirectoryThatIsNotThereScansToNothing(): void
    {
        $scanned = (new TestScanner([$this->directory->path . '/nowhere']))->scan();

        self::assertSame([], $scanned->declarations);
        self::assertSame([], $scanned->unanalyzed);
    }

    public function testDeclarationsGroupByCassetteName(): void
    {
        $this->file('TwoTest.php', <<<'PHP'
            <?php
            namespace App\Tests;

            use HttpVcr\Bridge\PHPUnit\UseCassette;

            final class TwoTest extends \PHPUnit\Framework\TestCase
            {
                #[UseCassette('shared')]
                public function testFirst(): void {}

                #[UseCassette('shared')]
                public function testSecond(): void {}

                #[UseCassette('alone')]
                public function testThird(): void {}
            }
            PHP);

        $grouped = $this->scan()->byCassette();

        self::assertSame(['alone', 'shared'], array_keys($grouped));
        self::assertCount(2, $grouped['shared']);
    }

    /**
     * @param list<CassetteDeclaration> $declarations
     *
     * @return array<string, string>
     */
    private function named(array $declarations): array
    {
        $named = [];

        foreach ($declarations as $declaration) {
            $named[$declaration->method] = $declaration->declared->name;
        }

        return $named;
    }

    private function file(string $name, string $content): void
    {
        $this->directory->write($name, $content);
    }

    private function scan(): ScannedTests
    {
        return (new TestScanner([$this->directory->path]))->scan();
    }
}
