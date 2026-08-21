<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Persistence;

use HttpVcr\Persistence\FilesystemCassettePersister;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemCassettePersister::class)]
final class FilesystemCassettePersisterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/http-vcr-persister-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testWritesAndReadsBackACassette(): void
    {
        $persister = $this->persister();

        $persister->write('shopify/get-product.json', '{"schemaVersion":1}');

        self::assertTrue($persister->exists('shopify/get-product.json'));
        self::assertSame('{"schemaVersion":1}', $persister->read('shopify/get-product.json'));
    }

    public function testReadingWhatIsNotThereGivesNullRatherThanAnError(): void
    {
        self::assertNull($this->persister()->read('nothing/here.json'));
        self::assertFalse($this->persister()->exists('nothing/here.json'));
    }

    public function testTheCassetteNameIsARelativePathSoSubdirectoriesAreCreated(): void
    {
        $this->persister()->write('a/b/c.json', 'x');

        self::assertFileExists($this->directory . '/a/b/c.json');
    }

    public function testDeleteRemovesTheFileAndIsSilentWhenThereIsNothingToRemove(): void
    {
        $persister = $this->persister();
        $persister->write('one.json', 'x');

        $persister->delete('one.json');
        $persister->delete('one.json');

        self::assertFalse($persister->exists('one.json'));
    }

    public function testListReturnsCassetteNamesWithoutTheExtension(): void
    {
        $persister = $this->persister();
        $persister->write('shopify/get-product.json', 'x');
        $persister->write('shopify/list-products.json', 'x');
        $persister->write('zendesk/get-ticket.json', 'x');

        self::assertSame(
            ['shopify/get-product', 'shopify/list-products', 'zendesk/get-ticket'],
            iterator_to_array($persister->list('json'), false),
        );
    }

    public function testListSkipsEverythingOutsideTheGivenFormat(): void
    {
        $persister = $this->persister();
        $persister->write('shopify/get-product.json', 'x');
        $persister->write('shopify/get-product.a1b2c3d4.bin', 'raw bytes');
        $persister->write('shopify/get-product.cassette-lock', '');

        self::assertSame(['shopify/get-product'], iterator_to_array($persister->list('json'), false));
    }

    public function testListNarrowsToAPrefix(): void
    {
        $persister = $this->persister();
        $persister->write('shopify/get-product.json', 'x');
        $persister->write('zendesk/get-ticket.json', 'x');

        self::assertSame(['shopify/get-product'], iterator_to_array($persister->list('json', 'shopify/'), false));
    }

    public function testListIsEmptyWhenTheDirectoryDoesNotExistYet(): void
    {
        self::assertSame([], iterator_to_array($this->persister()->list('json'), false));
    }

    public function testDescribeNamesTheFileAnErrorMessageWouldPointAt(): void
    {
        self::assertSame(
            $this->directory . '/shopify/get-product.json',
            $this->persister()->describe('shopify/get-product.json'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableNames(): iterable
    {
        yield 'traversal' => ['../outside.json'];
        yield 'traversal in the middle' => ['shopify/../../outside.json'];
        yield 'hidden file' => ['.secret.json'];
        yield 'empty segment' => ['shopify//get-product.json'];
    }

    #[DataProvider('unusableNames')]
    public function testASegmentThatCouldEscapeTheDirectoryIsRefused(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->persister()->write($key, 'x');
    }

    public function testCharactersNoFilesystemWantsAreReplacedRatherThanRefused(): void
    {
        $persister = $this->persister();

        $persister->write('api 2024:01/get product.json', 'x');

        self::assertFileExists($this->directory . '/api_2024_01/get_product.json');
    }

    public function testAHeldLockExcludesAnyoneElseOpeningTheSameFile(): void
    {
        $persister = $this->persister();
        $persister->lock('shopify/get-product.cassette-lock');

        $handle = fopen($this->directory . '/shopify/get-product.cassette-lock', 'c');
        self::assertIsResource($handle);
        self::assertFalse(flock($handle, LOCK_EX | LOCK_NB));

        $persister->unlock('shopify/get-product.cassette-lock');
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function testUnlockingWhatWasNeverLockedIsNotAnError(): void
    {
        $this->expectNotToPerformAssertions();

        $this->persister()->unlock('never/locked.cassette-lock');
    }

    private function persister(): FilesystemCassettePersister
    {
        return new FilesystemCassettePersister($this->directory);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
