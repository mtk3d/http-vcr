<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use HttpVcr\CassetteDirectoryMap;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CassetteDirectoryMap::class)]
final class CassetteDirectoryMapTest extends TestCase
{
    private const ROOT = '/project';

    public function testAModuleKeepsItsCassettesBesideItself(): void
    {
        $map = $this->map(['tests/Modules/*/' => '{match}/Cassettes']);

        self::assertSame(
            '/project/tests/Modules/Shopify/Cassettes',
            $map->directoryFor('/project/tests/Modules/Shopify/GetProductTest.php'),
        );
    }

    public function testTheDirectoryIsTheSameHowDeepInTheModuleTheTestSits(): void
    {
        $map = $this->map(['tests/Modules/*/' => '{match}/Cassettes']);

        self::assertSame(
            '/project/tests/Modules/Shopify/Cassettes',
            $map->directoryFor('/project/tests/Modules/Shopify/Api/Products/GetProductTest.php'),
        );
    }

    public function testWhatEachStarMatchedIsAvailableOnItsOwn(): void
    {
        $map = $this->map(['tests/Modules/*/' => 'tests/Cassettes/{1}']);

        self::assertSame(
            '/project/tests/Cassettes/Shopify',
            $map->directoryFor('/project/tests/Modules/Shopify/GetProductTest.php'),
        );
    }

    public function testAPatternHasToMatchAWholeSegment(): void
    {
        $map = $this->map(['tests/Modules/*/' => '{match}/Cassettes']);

        self::assertNull($map->directoryFor('/project/tests/ModulesLegacy/Shopify/GetProductTest.php'));
        self::assertNull($map->directoryFor('/project/tests/Unit/GetProductTest.php'));
    }

    public function testTheFirstPatternThatMatchesIsTheOneThatCounts(): void
    {
        $map = $this->map([
            'tests/Modules/Shopify/' => 'tests/Cassettes/shopify',
            'tests/Modules/*/' => '{match}/Cassettes',
        ]);

        self::assertSame(
            '/project/tests/Cassettes/shopify',
            $map->directoryFor('/project/tests/Modules/Shopify/GetProductTest.php'),
        );
        self::assertSame(
            '/project/tests/Modules/Stripe/Cassettes',
            $map->directoryFor('/project/tests/Modules/Stripe/ChargeTest.php'),
        );
    }

    public function testAnAbsoluteDirectoryIsLeftWhereItPoints(): void
    {
        $map = $this->map(['tests/Modules/*/' => '/var/fixtures/{1}']);

        self::assertSame('/var/fixtures/Shopify', $map->directoryFor('/project/tests/Modules/Shopify/GetProductTest.php'));
    }

    public function testADoubleStarCrossesDirectories(): void
    {
        $map = $this->map(['packages/**/tests/' => '{match}/Cassettes']);

        self::assertSame(
            '/project/packages/acme/shop/tests/Cassettes',
            $map->directoryFor('/project/packages/acme/shop/tests/CheckoutTest.php'),
        );
    }

    public function testAFileOutsideTheProjectMatchesNothing(): void
    {
        $map = $this->map(['tests/Modules/*/' => '{match}/Cassettes']);

        self::assertNull($map->directoryFor('/somewhere/else/tests/Modules/Shopify/GetProductTest.php'));
    }

    public function testARelativePathIsAlreadyRelativeToTheRoot(): void
    {
        $map = $this->map(['tests/Modules/*/' => '{match}/Cassettes']);

        self::assertSame(
            '/project/tests/Modules/Shopify/Cassettes',
            $map->directoryFor('tests/Modules/Shopify/GetProductTest.php'),
        );
    }

    public function testAnEmptyMapNeverDecidesAnything(): void
    {
        self::assertNull($this->map([])->directoryFor('/project/tests/Modules/Shopify/GetProductTest.php'));
    }

    public function testAPlaceholderNamingAStarThatIsNotThereIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"{2}"');

        $this->map(['tests/Modules/*/' => 'tests/Cassettes/{2}']);
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private function map(array $patterns): CassetteDirectoryMap
    {
        return new CassetteDirectoryMap($patterns, self::ROOT);
    }
}
