<?php

declare(strict_types=1);

namespace HttpVcr;

use HttpVcr\Clock\SystemClock;
use HttpVcr\Matching\MethodMatcher;
use HttpVcr\Matching\QueryStringMatcher;
use HttpVcr\Matching\RequestMatcherInterface;
use HttpVcr\Matching\UriMatcher;
use HttpVcr\Persistence\CassettePersisterInterface;
use HttpVcr\Persistence\FilesystemCassettePersister;
use HttpVcr\Serializer\CassetteSerializerInterface;
use HttpVcr\Serializer\JsonCassetteSerializer;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

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
     */
    private function __construct(
        private readonly ?string $cassetteDirectory,
        private readonly ?CassettePersisterInterface $persister,
        private readonly ?CassetteSerializerInterface $serializer,
        private readonly array $defaultMatchers,
        private readonly ?StrictMode $strictMode,
        private readonly ?ResponseFactoryInterface $responseFactory,
        private readonly ?StreamFactoryInterface $streamFactory,
        private readonly ?ClockInterface $clock,
        private readonly ?int $inlineBodyLimit,
        private readonly ?bool $scanRecordingsForSecrets,
        private readonly array $redact,
    ) {
    }

    /**
     * @param list<RequestMatcherInterface>    $defaultMatchers empty means Method + Uri + QueryString
     * @param array<string, callable(): mixed> $redact          placeholder to value provider, for a
     *                                                          secret every cassette in the project
     *                                                          would otherwise have to redact itself
     */
    public static function create(
        ?string $cassetteDirectory = null,
        ?CassettePersisterInterface $persister = null,
        ?CassetteSerializerInterface $serializer = null,
        array $defaultMatchers = [],
        ?StrictMode $strictMode = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?ClockInterface $clock = null,
        ?int $inlineBodyLimit = null,
        ?bool $scanRecordingsForSecrets = null,
        array $redact = [],
    ): self {
        return new self(
            $cassetteDirectory,
            $persister,
            $serializer,
            $defaultMatchers,
            $strictMode,
            $responseFactory,
            $streamFactory,
            $clock,
            $inlineBodyLimit,
            $scanRecordingsForSecrets,
            $redact,
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

    public function clock(): ClockInterface
    {
        return $this->clock ?? new SystemClock();
    }

    /**
     * The factories this project supplied, if any — the detection list fills in the rest.
     *
     * @return array<class-string, object>
     */
    public function psr17Factories(): array
    {
        return array_filter([
            ResponseFactoryInterface::class => $this->responseFactory,
            StreamFactoryInterface::class => $this->streamFactory,
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
