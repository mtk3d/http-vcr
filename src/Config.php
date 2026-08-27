<?php

declare(strict_types=1);

namespace HttpVcr;

use Closure;
use DateInterval;
use HttpVcr\Clock\SystemClock;
use HttpVcr\Exception\MissingDependencyException;
use HttpVcr\Matching\MethodMatcher;
use HttpVcr\Matching\QueryStringMatcher;
use HttpVcr\Matching\RequestMatcherInterface;
use HttpVcr\Matching\UriMatcher;
use HttpVcr\Persistence\CassettePersisterInterface;
use HttpVcr\Persistence\FilesystemCassettePersister;
use HttpVcr\Scope\CassetteScopeResolverInterface;
use HttpVcr\Scope\NullScopeResolver;
use HttpVcr\Serializer\CassetteSerializerInterface;
use HttpVcr\Serializer\JsonCassetteSerializer;
use HttpVcr\Serializer\YamlCassetteSerializer;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Project-wide defaults for every VcrClient the process constructs.
 *
 * This is the one genuinely process-wide thing in the library, and it is frozen before the
 * first VcrClient exists precisely so that "two tests in the same process never interfere"
 * stays true instead of depending on execution order.
 */
final class Config
{
    private const FILE = 'http-vcr.php';

    /**
     * The clients looked for when nothing was configured, in order, each with what its
     * constructor wants. Every one of them is a PSR-18 client a project testing HTTP would
     * plausibly already have.
     *
     * @var array<string, string>
     */
    private const CLIENTS = [
        'GuzzleHttp\Client' => 'nothing',
        'Symfony\Component\HttpClient\Psr18Client' => 'psr-17 factories',
        'Buzz\Client\FileGetContents' => 'a response factory',
    ];

    private static ?self $global = null;

    private static bool $frozen = false;

    /**
     * @param  list<RequestMatcherInterface>  $defaultMatchers
     * @param  array<string, callable(): mixed>  $redact
     * @param  array<string, Provider>  $providers
     * @param  list<string>  $testDirectories
     */
    private function __construct(
        private readonly ?string $cassetteDirectory,
        private readonly ?CassettePersisterInterface $persister,
        private readonly ?CassetteSerializerInterface $serializer,
        private readonly array $defaultMatchers,
        private readonly ?StrictMode $strictMode,
        private readonly ?DateInterval $staleAfter,
        private readonly ?CassetteScopeResolverInterface $scopeResolver,
        private readonly ?ResponseFactoryInterface $responseFactory,
        private readonly ?StreamFactoryInterface $streamFactory,
        private readonly ?RequestFactoryInterface $requestFactory,
        private readonly ?UriFactoryInterface $uriFactory,
        private readonly ?ClockInterface $clock,
        private readonly ?int $inlineBodyLimit,
        private readonly ?bool $scanRecordingsForSecrets,
        private readonly array $redact,
        private readonly array $providers,
        private readonly array $testDirectories,
        private readonly ?Closure $innerClientFactory,
    ) {
        $this->refuseAmbiguousProviders();
    }

    /**
     * @param  list<RequestMatcherInterface>  $defaultMatchers  empty means Method + Uri + QueryString
     * @param  array<string, callable(): mixed>  $redact  placeholder to value provider, for a
     *                                                    secret every cassette in the project
     *                                                    would otherwise have to redact itself
     * @param  array<string, Provider>  $providers  named APIs, recognised by host (§3.12)
     * @param  list<string>  $testDirectories  where the CLI looks for the test
     *                                         files it scans; nothing else reads it
     * @param  (callable(): ClientInterface)|null  $innerClientFactory  the real client #[UseCassette]
     *                                                                  records through
     */
    public static function create(
        ?string $cassetteDirectory = null,
        ?CassettePersisterInterface $persister = null,
        ?CassetteSerializerInterface $serializer = null,
        array $defaultMatchers = [],
        ?StrictMode $strictMode = null,
        DateInterval|Stale|null $staleAfter = null,
        ?CassetteScopeResolverInterface $scopeResolver = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?UriFactoryInterface $uriFactory = null,
        ?ClockInterface $clock = null,
        ?int $inlineBodyLimit = null,
        ?bool $scanRecordingsForSecrets = null,
        array $redact = [],
        array $providers = [],
        array $testDirectories = [],
        ?callable $innerClientFactory = null,
    ): self {
        return new self(
            $cassetteDirectory,
            $persister,
            $serializer,
            $defaultMatchers,
            $strictMode,
            Stale::asInterval($staleAfter),
            $scopeResolver,
            $responseFactory,
            $streamFactory,
            $requestFactory,
            $uriFactory,
            $clock,
            $inlineBodyLimit,
            $scanRecordingsForSecrets,
            $redact,
            $providers,
            $testDirectories,
            $innerClientFactory === null ? null : $innerClientFactory(...),
        );
    }

    /**
     * The project's configuration, read from http-vcr.php the first time anything asks for
     * it. Not finding a file is not an error — it means the defaults apply.
     */
    public static function global(): self
    {
        return self::$global ??= self::discover((string) getcwd()) ?? self::create();
    }

    public static function replaceGlobal(self $config): void
    {
        self::refuseLateConfiguration();

        // Field by field over whatever http-vcr.php said, rather than instead of it: these
        // are two entrances to one configuration, and the call written in code wins over the
        // file picked up in the background (§3.14).
        self::$global = $config->over(self::global());
    }

    /**
     * The configuration file named on the command line, in place of the discovered one
     * (`vendor/bin/http-vcr --config=…`) — for a monorepo or any layout where walking up
     * from the working directory finds the wrong project (§3.12).
     */
    public static function useFile(string $path): void
    {
        self::refuseLateConfiguration();

        self::$global = self::loadFile($path);
    }

    /**
     * Walks up from $directory looking for http-vcr.php, and stops at the directory holding
     * composer.json whether or not it found one. That boundary is deliberate: on a shared CI
     * runner or in a monorepo, a search that kept going would eventually reach $HOME and
     * pick up a configuration belonging to something else entirely.
     */
    public static function discover(string $directory): ?self
    {
        while ($directory !== '') {
            if (is_file($directory.'/'.self::FILE)) {
                return self::loadFile($directory.'/'.self::FILE);
            }

            if (is_file($directory.'/composer.json')) {
                return null;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }

        return null;
    }

    public static function loadFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException(sprintf('There is no configuration file at %s.', $path));
        }

        $config = require $path;

        if (! $config instanceof self) {
            throw new LogicException(sprintf(
                '%s has to return HttpVcr\Config::create(...); it returned %s.',
                $path,
                get_debug_type($config),
            ));
        }

        return $config;
    }

    private static function refuseLateConfiguration(): void
    {
        if (self::$frozen) {
            throw new LogicException(
                'VcrClient::configure() has to be called before the first VcrClient is constructed — '
                .'a PHPUnit bootstrap, typically. Configuration that can change mid-run would make two '
                .'tests in one process depend on the order they happen to run in.',
            );
        }
    }

    /**
     * This configuration laid over another, taking every field it actually declared.
     */
    private function over(self $base): self
    {
        return new self(
            $this->cassetteDirectory ?? $base->cassetteDirectory,
            $this->persister ?? $base->persister,
            $this->serializer ?? $base->serializer,
            $this->defaultMatchers !== [] ? $this->defaultMatchers : $base->defaultMatchers,
            $this->strictMode ?? $base->strictMode,
            $this->staleAfter ?? $base->staleAfter,
            $this->scopeResolver ?? $base->scopeResolver,
            $this->responseFactory ?? $base->responseFactory,
            $this->streamFactory ?? $base->streamFactory,
            $this->requestFactory ?? $base->requestFactory,
            $this->uriFactory ?? $base->uriFactory,
            $this->clock ?? $base->clock,
            $this->inlineBodyLimit ?? $base->inlineBodyLimit,
            $this->scanRecordingsForSecrets ?? $base->scanRecordingsForSecrets,
            // Project-wide redaction rules add up rather than replace: a rule in the file
            // and a rule in the bootstrap are both things the project asked for.
            $this->redact + $base->redact,
            $this->providers !== [] ? $this->providers : $base->providers,
            $this->testDirectories !== [] ? $this->testDirectories : $base->testDirectories,
            $this->innerClientFactory ?? $base->innerClientFactory,
        );
    }

    /**
     * Called when the first VcrClient of the process is constructed.
     */
    public static function freeze(): void
    {
        self::$frozen = true;
    }

    /**
     * @internal for the library's own tests, which need more than one process-wide
     *           configuration over their lifetime
     */
    public static function reset(): void
    {
        self::$global = null;
        self::$frozen = false;
    }

    /**
     * YAML wherever `symfony/yaml` is installed, JSON otherwise (§7 decision 2).
     *
     * YAML is the format worth reading: a body with newlines in it is a literal block
     * rather than one escaped line, so an HTML or XML response stays legible in a diff.
     * It cannot be the unconditional default, because the record/replay path may depend on
     * nothing but the PSR packages (§1) — hence a format that follows what the project has
     * rather than a dependency the library insists on.
     *
     * A project that would rather not have the format follow its vendor directory names
     * one in `http-vcr.php`, which always wins. Switching an existing project either way
     * is what `vendor/bin/http-vcr migrate` is for: cassettes are only ever looked for
     * under the extension their serializer owns, so files left in the old format are
     * invisible rather than migrated.
     */
    public function serializer(): CassetteSerializerInterface
    {
        if ($this->serializer !== null) {
            return $this->serializer;
        }

        return class_exists(Yaml::class) ? new YamlCassetteSerializer : new JsonCassetteSerializer;
    }

    public function persister(): CassettePersisterInterface
    {
        return $this->persister ?? new FilesystemCassettePersister($this->cassetteDirectory());
    }

    /**
     * @return list<RequestMatcherInterface>
     */
    public function defaultMatchers(): array
    {
        return $this->defaultMatchers !== []
            ? $this->defaultMatchers
            : [new MethodMatcher, new UriMatcher, new QueryStringMatcher];
    }

    /**
     * What every cassette in the project asserts about the way it was replayed. None by
     * default: AllPlayed/InOrder are worth declaring for one well-understood action, and a
     * blanket setting mostly produces false alarms on unrelated cassettes (§3.6).
     */
    public function strictMode(): StrictMode
    {
        return $this->strictMode ?? StrictMode::None;
    }

    /**
     * How long a recording stays fresh, for every cassette in the project. Null means
     * freshness isn't tracked at all — the correct opt-in default, since only the author of
     * an integration knows how fast the API behind it moves (§3.7).
     */
    public function staleAfter(): ?DateInterval
    {
        return $this->staleAfter;
    }

    /**
     * How a cassette name is split across files, for every cassette in the project (§3.8).
     * No splitting by default.
     */
    public function scopeResolver(): CassetteScopeResolverInterface
    {
        return $this->scopeResolver ?? new NullScopeResolver;
    }

    /**
     * Bodies past this many bytes are kept in a file of their own rather than inside the
     * cassette. 1 MiB by default — big enough that ordinary API payloads never notice.
     */
    public function inlineBodyLimit(): int
    {
        return $this->inlineBodyLimit ?? 1_048_576;
    }

    /**
     * Redaction rules every cassette in the project gets, registered before anything an
     * individual VcrClient adds — so a project-wide rule always runs first.
     *
     * @return array<string, callable(): mixed>
     */
    public function redactions(): array
    {
        return $this->redact;
    }

    /**
     * Whether a session that recorded anything checks what it wrote for credentials (§3.4).
     * On by default, and switched off only here rather than through an environment
     * variable: silencing a warning about secrets belongs in the repository, where a code
     * review can see it.
     */
    public function scanRecordingsForSecrets(): bool
    {
        return $this->scanRecordingsForSecrets ?? true;
    }

    /**
     * The APIs this project has named, if any (§3.12).
     *
     * @return array<string, Provider>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * The name this project gave the API answering on that host, if it gave it one.
     */
    public function providerFor(string $host): ?string
    {
        foreach ($this->providers as $name => $provider) {
            if ($provider->covers($host)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A host belonging to two providers is refused here rather than settled by declaration
     * order: with the order deciding, `VCR_ERASE_TAPE=@shopify` would erase one thing today
     * and another after someone sorted the array.
     */
    private function refuseAmbiguousProviders(): void
    {
        foreach ($this->providers as $name => $provider) {
            foreach ($this->providers as $other => $rival) {
                foreach ($provider->hosts as $pattern) {
                    foreach ($rival->hosts as $rivalPattern) {
                        if ($name !== $other && Provider::overlap($pattern, $rivalPattern)) {
                            throw new LogicException(sprintf(
                                'Providers "%s" (%s) and "%s" (%s) both claim the same host. One host '
                                .'belongs to one API, so that a selector always means the same thing.',
                                $name,
                                $pattern,
                                $other,
                                $rivalPattern,
                            ));
                        }
                    }
                }
            }
        }
    }

    public function clock(): ClockInterface
    {
        return $this->clock ?? new SystemClock;
    }

    /**
     * The factories this project supplied, if any — the detection list fills in the rest.
     * All four of them: the core uses two, and the Symfony bridge asks the same
     * configuration for the request and URI factories it needs (§3.10).
     *
     * @return array<class-string, object>
     */
    public function psr17Factories(): array
    {
        return array_filter([
            ResponseFactoryInterface::class => $this->responseFactory,
            StreamFactoryInterface::class => $this->streamFactory,
            RequestFactoryInterface::class => $this->requestFactory,
            UriFactoryInterface::class => $this->uriFactory,
        ]);
    }

    /**
     * One rule for every entry point — the PHPUnit bridge, a hand-built VcrClient, the CLI:
     * `tests/Cassettes/` relative to the project root, which is the directory holding
     * composer.json. A cassette name is a path inside it.
     */
    public function cassetteDirectory(): string
    {
        return $this->cassetteDirectory ?? self::projectRoot().'/tests/Cassettes';
    }

    /**
     * Where the CLI looks for the test files it scans — `tests/` under the project root by
     * default, the same root rule as the cassette directory (§3.12).
     *
     * @return list<string>
     */
    public function testDirectories(): array
    {
        return $this->testDirectories !== [] ? $this->testDirectories : [self::projectRoot().'/tests'];
    }

    /**
     * The real client the PHPUnit bridge records through, since it builds VcrClient on the
     * test's behalf and has to hand it a transport (§3.14).
     *
     * Same shape as the PSR-17 factories: what the project configured, then a closed
     * detection list, then an exception naming what to install. A replaying test never
     * touches this, so a missing client is only an error at the moment something records.
     */
    public function innerClient(): ClientInterface
    {
        if ($this->innerClientFactory !== null) {
            $client = ($this->innerClientFactory)();

            if (! $client instanceof ClientInterface) {
                throw new LogicException(sprintf(
                    'innerClientFactory has to return a PSR-18 client; it returned %s.',
                    get_debug_type($client),
                ));
            }

            return $client;
        }

        foreach (self::CLIENTS as $class => $arguments) {
            if (class_exists($class)) {
                return $this->construct($class, $arguments);
            }
        }

        throw MissingDependencyException::noHttpClient(array_keys(self::CLIENTS));
    }

    /**
     * The constructor arguments are chosen by what the class asked for rather than by its
     * name, so nothing here names a class this installation may not have.
     */
    private function construct(string $class, string $arguments): ClientInterface
    {
        $factories = new Psr17FactoryResolver($this->psr17Factories());

        $client = new $class(...match ($arguments) {
            'a response factory' => [$factories->responseFactory()],
            'psr-17 factories' => [null, $factories->responseFactory(), $factories->streamFactory()],
            default => [],
        });

        if (! $client instanceof ClientInterface) {
            throw MissingDependencyException::noHttpClient(array_keys(self::CLIENTS));
        }

        return $client;
    }

    private static function projectRoot(): string
    {
        $directory = getcwd();

        if ($directory === false) {
            return '.';
        }

        while (! is_file($directory.'/composer.json')) {
            $parent = dirname($directory);

            if ($parent === $directory) {
                return (string) getcwd();
            }

            $directory = $parent;
        }

        return $directory;
    }
}
