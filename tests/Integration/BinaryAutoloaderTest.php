<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `vendor/bin/http-vcr` reads the host project's cassettes, so it has to boot on the host
 * project's autoloader (§7 decision 79). Both candidate paths exist at once whenever
 * http-vcr is installed as a path repository, and the wrong one there is silent: our own
 * vendor/ carries symfony/yaml, which flips the default serializer to YAML and makes every
 * JSON cassette in the host project vanish from `scan-secrets` and `providers`.
 *
 * The two layouts are built out of stub autoloaders that announce themselves and exit, so
 * what is asserted is which file the binary picked, not what the commands did afterwards.
 */
#[CoversNothing]
final class BinaryAutoloaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/http-vcr-bin-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAnInstalledPackagePrefersTheHostProjectsAutoloader(): void
    {
        $binary = $this->installAt('vendor/mtk3d/http-vcr');

        $this->writeAutoloader('vendor/autoload.php', 'host');
        $this->writeAutoloader('vendor/mtk3d/http-vcr/vendor/autoload.php', 'own');

        self::assertSame('host', $this->execute($binary));
    }

    public function testAnOwnCheckoutFallsBackToItsOwnAutoloader(): void
    {
        $binary = $this->installAt('http-vcr');

        $this->writeAutoloader('http-vcr/vendor/autoload.php', 'own');

        self::assertSame('own', $this->execute($binary));
    }

    public function testWithNeitherItSaysWhatIsMissing(): void
    {
        $binary = $this->installAt('vendor/mtk3d/http-vcr');

        self::assertStringContainsString('vendor/autoload.php', $this->execute($binary));
    }

    /**
     * @return string the binary's path, copied verbatim so the test runs the real file
     */
    private function installAt(string $package): string
    {
        $path = $this->root.'/'.$package.'/bin/http-vcr';

        $this->write($path, (string) file_get_contents(__DIR__.'/../../bin/http-vcr'));

        return $path;
    }

    private function writeAutoloader(string $path, string $marker): void
    {
        $this->write($this->root.'/'.$path, '<?php echo '.var_export($marker, true).'; exit(0);');
    }

    private function execute(string $binary): string
    {
        $output = [];

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($binary).' 2>&1', $output);

        return implode("\n", $output);
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            is_dir($path.'/'.$entry) ? $this->removeDirectory($path.'/'.$entry) : unlink($path.'/'.$entry);
        }

        rmdir($path);
    }
}
