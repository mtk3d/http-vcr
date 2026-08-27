<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

use Closure;
use DateInterval;
use HttpVcr\Environment;
use HttpVcr\Hook\HookRegistry;
use HttpVcr\Hook\RedactionHooks;
use HttpVcr\Matching\CompositeMatcher;
use HttpVcr\Persistence\CassettePersisterInterface;
use HttpVcr\RecordMode;
use HttpVcr\Scope\CassetteScopeResolverInterface;
use HttpVcr\Scope\NullScopeResolver;
use HttpVcr\SecretScanner;
use HttpVcr\Serializer\CassetteSerializerInterface;
use HttpVcr\StrictMode;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The cassette as the test names it, for the length of one test.
 *
 * Without a scope resolver that is one file and this object is a thin front for it. With
 * one, a single name spans a file per scope (§3.8) — resolved per request, opened on
 * demand, and independent of one another: each keeps its own lock, its own consumption
 * counters and its own strict-mode verdict, because pooling them would hide which of the
 * files a leftover interaction is actually in (§3.6).
 *
 * What is shared is what belongs to the test rather than to a file: the hook pipeline, the
 * redaction rules, and the flag saying the first request has gone out.
 */
final class CassetteSession
{
    /** @var array<string, CassetteManager> */
    private array $cassettes = [];

    private bool $started = false;

    public function __construct(
        private readonly string $name,
        private readonly CassettePersisterInterface $persister,
        private readonly CassetteSerializerInterface $serializer,
        private readonly CompositeMatcher $matcher,
        private readonly ClockInterface $clock,
        private readonly Environment $environment,
        private readonly RecordMode $mode = RecordMode::RecordIfAbsent,
        private readonly StrictMode $strictMode = StrictMode::None,
        private readonly ?DateInterval $staleAfter = null,
        private readonly bool $repeatablePlayback = false,
        private readonly bool $locked = false,
        private readonly int $inlineBodyLimit = 1_048_576,
        private readonly CassetteScopeResolverInterface $scopes = new NullScopeResolver,
        public readonly HookRegistry $hooks = new HookRegistry,
        public readonly RedactionHooks $redaction = new RedactionHooks,
        private readonly ?SecretScanner $scanner = null,
        private readonly ?Closure $warn = null,
        private readonly bool $reportUnplayed = true,
    ) {
        // Registered before any file is opened, so redaction is always the first hook in
        // either direction: a project-wide rule runs ahead of anything added by hand.
        $this->hooks->addBeforeRecord($this->redaction->beforeRecord(...));
        $this->hooks->addBeforePlayback($this->redaction->beforePlayback(...));
    }

    /**
     * Marks the session as under way: from here on it is too late to register a hook or
     * anything else that would have changed an interaction already on its way past.
     *
     * On the session rather than on VcrClient, because the Guzzle bridge builds a fresh
     * client per request out of withInner() and a flag on the instance would never see a
     * second request (§3.14).
     */
    public function begin(): void
    {
        $this->started = true;
    }

    public function hasStarted(): bool
    {
        return $this->started;
    }

    /**
     * The cassette file this request belongs in.
     */
    public function for(RequestInterface $request): CassetteManager
    {
        $scope = $this->scopes->resolve($request);

        return $this->cassette($scope === null ? null : $this->sanitize($scope));
    }

    /**
     * Ends every file this session opened: each gives back its lock first, and only then
     * does any of them raise a strict-mode failure — one scope's failed assertion has no
     * business leaving another scope's lock behind (§3.6).
     */
    public function close(): void
    {
        if ($this->strictMode !== StrictMode::None && $this->cassettes === []) {
            // Nothing was ever asked of the cassette, and it has still promised something
            // about what it holds. With no request there is no scope to compute either, so
            // the file to check is the one the test named.
            $this->cassette(null);
        }

        foreach ($this->cassettes as $cassette) {
            $cassette->prepare();
        }

        $this->release();

        foreach ($this->cassettes as $cassette) {
            $cassette->verify();
        }
    }

    /**
     * The half of close() that only gives things back, for the destructor to call — see
     * {@see CassetteManager::release()} for why an assertion doesn't belong there.
     */
    public function release(): void
    {
        foreach ($this->cassettes as $cassette) {
            $cassette->release();
        }
    }

    private function cassette(?string $scope): CassetteManager
    {
        return $this->cassettes[$scope ?? ''] ??= new CassetteManager(
            $this->name,
            $scope,
            $this->persister,
            $this->serializer,
            $this->matcher,
            $this->clock,
            $this->environment,
            $this->mode,
            $this->strictMode,
            $this->staleAfter,
            $this->repeatablePlayback,
            $this->locked,
            $this->inlineBodyLimit,
            $this->hooks,
            $this->redaction,
            $this->scanner,
            $this->warn,
            $this->reportUnplayed,
        );
    }

    /**
     * A scope goes straight into a file name, and a callback resolver can return anything
     * at all — a header value, whatever a closure computes. So it is treated as exactly one
     * path segment: everything outside the whitelist becomes `_`, `/` included, since a
     * scope never means a subdirectory. A result that would be empty, a dot segment or a
     * hidden file is refused rather than mangled into something that resolves elsewhere
     * (§3.8).
     */
    private function sanitize(string $scope): string
    {
        $sanitized = (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $scope);

        if ($sanitized === '' || str_starts_with($sanitized, '.')) {
            throw new InvalidArgumentException(sprintf(
                'The scope resolver returned "%s" for cassette "%s", which cannot be part of a file name.',
                $scope,
                $this->name,
            ));
        }

        return $sanitized;
    }
}
