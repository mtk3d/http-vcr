<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Console\Application;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\VcrClient;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * http-vcr.php is optional, so its absence is never an error — but when it is there, both
 * the PHPUnit bridge and the CLI have to find it the same way (§3.12).
 */
#[CoversClass(Config::class)]
#[CoversClass(Application::class)]
final class ConfigurationFileTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        // realpath, because __DIR__ inside the written file resolves symlinks (/tmp on
        // macOS) and the assertions compare the two.
        $this->project = (string) realpath(sys_get_temp_dir()).'/http-vcr-project-'.bin2hex(random_bytes(6));

        mkdir($this->project.'/src/nested', 0o777, true);
        file_put_contents($this->project.'/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);

        Config::reset();
    }

    public function testFindsTheFileByWalkingUpFromWhereTheProcessStarted(): void
    {
        $this->write($this->project.'/http-vcr.php', "cassetteDirectory: __DIR__ . '/tests/Recordings'");

        $config = Config::discover($this->project.'/src/nested');

        self::assertNotNull($config);
        self::assertSame($this->project.'/tests/Recordings', $config->cassetteDirectory());
    }

    public function testTheSearchStopsAtTheProjectRootRatherThanReachingIntoWhateverIsAbove(): void
    {
        $this->write($this->project.'/http-vcr.php', "cassetteDirectory: '/should/not/be/found'");

        mkdir($this->project.'/packages/inner', 0o777, true);
        file_put_contents($this->project.'/packages/inner/composer.json', '{}');

        self::assertNull(Config::discover($this->project.'/packages/inner'));
    }

    public function testNoFileIsNotAnErrorItIsJustTheDefaults(): void
    {
        self::assertNull(Config::discover($this->project.'/src/nested'));
    }

    public function testAFileThatReturnsSomethingElseSaysSoRatherThanFailingLater(): void
    {
        file_put_contents($this->project.'/http-vcr.php', "<?php\n\nreturn ['cassetteDirectory' => 'nope'];\n");

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has to return HttpVcr\Config::create(...); it returned array.');

        Config::discover($this->project);
    }

    public function testAConfigPathThatDoesNotExistIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::loadFile($this->project.'/nowhere.php');
    }

    public function testConfigureOverridesTheFileFieldByFieldRatherThanWholesale(): void
    {
        $cassettes = new CassetteDirectory;
        $this->write(
            $this->project.'/http-vcr.php',
            "cassetteDirectory: '/from/the/file', inlineBodyLimit: 4096",
        );

        Config::useFile($this->project.'/http-vcr.php');
        VcrClient::configure(persister: $cassettes->persister());

        // The file said both of these and the call said neither, so both survive.
        self::assertSame('/from/the/file', Config::global()->cassetteDirectory());
        self::assertSame(4096, Config::global()->inlineBodyLimit());
        self::assertSame($cassettes->persister()::class, Config::global()->persister()::class);
    }

    public function testTheCliTakesTheFileNamedOnItsCommandLine(): void
    {
        $this->write($this->project.'/elsewhere.php', "cassetteDirectory: '/named/on/the/command/line'");

        (new Application)->doRun(
            new ArrayInput(['command' => 'list', '--config' => $this->project.'/elsewhere.php']),
            new NullOutput,
        );

        self::assertSame('/named/on/the/command/line', Config::global()->cassetteDirectory());
    }

    /**
     * A configuration file exactly as a project would write one.
     */
    private function write(string $path, string $arguments): void
    {
        file_put_contents($path, sprintf("<?php\n\nreturn HttpVcr\\Config::create(%s);\n", $arguments));
    }

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->remove($path) : unlink($path);
        }

        rmdir($directory);
    }
}
