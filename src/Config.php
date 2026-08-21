<?php

declare(strict_types=1);

namespace HttpVcr;

use DateInterval;
use HttpVcr\Clock\SystemClock;
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
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Project-wide defaults for every VcrClient the process constructs.
 *
 * This is the one genuinely process-wide thing in the library, and it is frozen before the
 * first VcrClient exists precisely so that "two tests in the same process never interfere"
 * stays true instead of depending on execution order.
 */
final class Config
{
    private static ?self $global = null;

    private static bool $frozen = false;

    /**
     * @param list<RequestMatcherInterface>    $defaultMatchers
     * @param array<string, callable(): mixed> $redact
     * @param array<string, Provider>          $providers
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
    ) {
        $this->refuseAmbiguousProviders();
    }

    /**
     * @param list<RequestMatcherInterface>    $defaultMatchers empty means Method + Uri + QueryString
     * @param array<string, callable(): mixed> $redact          placeholder to value provider, for a
     *                                                          secret every cassette in the project
     *                                                          would otherwise have to redact itself
     * @param array<string, Provider>          $providers       named APIs, recognised by host (§3.12)
     */
    public static function create(
        ?string $cassetteDirectory = null,
        ?CassettePersisterInterface $persister = null,
        ?CassetteSerializerInterface $serializer = null,
        array $defaultMatchers = [],
        ?StrictMode $strictMode = null,
        ?DateInterval $staleAfter = null,
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
    ): self {
        return new self(
            $cassetteDirectory,
            $persister,
            $serializer,
            $defaultMatchers,
            $strictMode,
            $staleAfter,
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
        );
    }

    public static function global(): self
    {
        return self::$global ??= self::create();
    }

    public static function replaceGlobal(self $config): void
    {
        if (self::$frozen) {
            throw new LogicException(
                'VcrClient::configure() has to be called before the first VcrClient is constructed — '
                . 'a PHPUnit bootstrap, typically. Configuration that can change mid-run would make two '
                . 'tests in one process depend on the order they happen to run in.',
            );
        }

        self::$global = $config;
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

    public function serializer(): CassetteSerializerInterface
    {
        return $this->serializer ?? new JsonCassetteSerializer();
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
            : [new MethodMatcher(), new UriMatcher(), new QueryStringMatcher()];
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
        return $this->scopeResolver ?? new NullScopeResolver();
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
                                . 'belongs to one API, so that a selector always means the same thing.',
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
        return $this->clock ?? new SystemClock();
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
        return $this->cassetteDirectory ?? self::projectRoot() . '/tests/Cassettes';
    }

    private static function projectRoot(): string
    {
        $directory = getcwd();

        if ($directory === false) {
            return '.';
        }

        while (!is_file($directory . '/composer.json')) {
            $parent = dirname($directory);

            if ($parent === $directory) {
                return (string) getcwd();
            }

            $directory = $parent;
        }

        return $directory;
    }
}
