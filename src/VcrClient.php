<?php

declare(strict_types=1);

namespace HttpVcr;

use DateInterval;
use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Cassette\CassetteSession;
use HttpVcr\Cassette\ErrorCategory;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedError;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Exception\CassetteNotFoundException;
use HttpVcr\Exception\MissingEnvironmentVariableException;
use HttpVcr\Exception\NoMatchingInteractionException;
use HttpVcr\Exception\RecordingNotAllowedException;
use HttpVcr\Exception\VcrNetworkException;
use HttpVcr\Exception\VcrRequestException;
use HttpVcr\Matching\CompositeMatcher;
use HttpVcr\Matching\RequestMatcherInterface;
use HttpVcr\Persistence\CassettePersisterInterface;
use HttpVcr\Scope\CassetteScopeResolverInterface;
use HttpVcr\Serializer\CassetteSerializerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * A PSR-18 client that replays a cassette instead of making requests — and records one
 * when there is nothing to replay yet.
 *
 * A decorator, not a patch: nothing here touches curl, a stream wrapper, or anything
 * outside this object, so two instances in one process never interfere.
 */
final class VcrClient implements ClientInterface
{
    private readonly CassetteSession $cassette;

    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /**
     * Whether this instance is the one that opened the session, as opposed to a satellite
     * withInner() handed a middleware for the length of one request.
     */
    private bool $ownsSession = true;

    private readonly Environment $environment;

    /**
     * The project configuration this client was built against, kept because two of its
     * parts — the named providers and their requiresEnv — are consulted per request rather
     * than once at construction.
     */
    private readonly Config $config;

    /**
     * @param  ClientInterface|null  $inner  the real client, used only when actually recording
     * @param  list<RequestMatcherInterface>  $matchers  empty means the project default set
     * @param  list<string>  $requiresEnv  variables checked when this cassette is about
     *                                     to record something for real (§3.12)
     * @param  (callable(string): void)|null  $warn  where the session's warnings go — the
     *                                               secret scan's findings, and a forced
     *                                               recording a lock made a no-op. Standard
     *                                               error without one; a test harness passes
     *                                               its own so a run can report them together
     *                                               rather than scattered through the output
     */
    public function __construct(
        private ?ClientInterface $inner,
        string $cassette,
        RecordMode $mode = RecordMode::RecordIfAbsent,
        array $matchers = [],
        ?StrictMode $strictMode = null,
        DateInterval|Stale|null $staleAfter = null,
        private readonly array $requiresEnv = [],
        private readonly bool $recordTransportErrors = false,
        private readonly bool $decodeCompressedResponse = true,
        ?int $inlineBodyLimit = null,
        bool $repeatablePlayback = false,
        bool $locked = false,
        ?CassetteScopeResolverInterface $scopeResolver = null,
        ?CassettePersisterInterface $persister = null,
        ?CassetteSerializerInterface $serializer = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?ClockInterface $clock = null,
        ?callable $warn = null,
    ) {
        Config::freeze();
        $config = Config::global();

        $factories = new Psr17FactoryResolver(array_filter([
            ResponseFactoryInterface::class => $responseFactory,
            StreamFactoryInterface::class => $streamFactory,
        ]) + $config->psr17Factories());

        $this->responseFactory = $factories->responseFactory();
        $this->streamFactory = $factories->streamFactory();

        $this->config = $config;
        $this->environment = Environment::fromSystem($config->providers());

        $this->cassette = new CassetteSession(
            $cassette,
            $persister ?? $config->persister(),
            $serializer ?? $config->serializer(),
            CompositeMatcher::of($matchers !== [] ? $matchers : $config->defaultMatchers()),
            $clock ?? $config->clock(),
            $this->environment,
            $mode,
            $strictMode ?? $config->strictMode(),
            Stale::asInterval($staleAfter) ?? $config->staleAfter(),
            $repeatablePlayback,
            $locked,
            $inlineBodyLimit ?? $config->inlineBodyLimit(),
            $scopeResolver ?? $config->scopeResolver(),
            scanner: $config->scanRecordingsForSecrets() ? new SecretScanner : null,
            warn: $warn === null ? null : $warn(...),
        );

        foreach ($config->redactions() as $placeholder => $value) {
            $this->cassette->redaction->redact($placeholder, $value);
        }
    }

    /**
     * Project-wide defaults, for a bootstrap that would rather write code than a config
     * file. Has to run before the first VcrClient of the process exists; afterwards it
     * throws, so that two tests in one process can't see different defaults depending on
     * the order they ran in.
     *
     * @param  list<RequestMatcherInterface>  $defaultMatchers
     * @param  array<string, callable(): mixed>  $redact  project-wide redaction rules
     * @param  array<string, Provider>  $providers  named APIs, recognised by host
     * @param  list<string>  $testDirectories  where the CLI scans for tests
     * @param  (callable(): ClientInterface)|null  $innerClientFactory  the client #[UseCassette] records through
     */
    public static function configure(
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
        ?bool $scanRecordingsForSecrets = null,
        array $redact = [],
        array $providers = [],
        array $testDirectories = [],
        ?callable $innerClientFactory = null,
    ): void {
        Config::replaceGlobal(Config::create(
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
            scanRecordingsForSecrets: $scanRecordingsForSecrets,
            redact: $redact,
            providers: $providers,
            testDirectories: $testDirectories,
            innerClientFactory: $innerClientFactory,
        ));
    }

    /**
     * The same client recording through a different transport: a new instance around the
     * *same* cassette session, with everything else — mode, matchers, hooks, redaction —
     * carried over untouched (§3.9).
     *
     * What a middleware needs, and the reason the session is an object of its own: the real
     * request has to travel through the rest of the handler stack rather than around it, so
     * the transport is known per request while the cassette is not. Sharing the session is
     * what keeps replay consumption, the file lock and the configuration freeze counting
     * across the whole run instead of resetting with every request (§3.14).
     */
    public function withInner(ClientInterface $inner): self
    {
        $satellite = clone $this;

        $satellite->inner = $inner;

        // The session outlives this instance: a middleware drops its satellite after every
        // request, and a lock given back there would be given back mid-run.
        $satellite->ownsSession = false;

        return $satellite;
    }

    /**
     * Replaces a secret with $placeholder everywhere it appears in an interaction, in both
     * directions: the placeholder goes to disk, and the real value comes back on replay —
     * into the recorded request before matching compares it, and into the response before
     * the code under test receives it (§3.4).
     *
     * @param  callable(): mixed  $value  read when it is needed, not when this is called, so
     *                                    a test may set the variable it reads afterwards
     */
    public function redact(string $placeholder, callable $value): void
    {
        $this->configuring('redact');

        $this->cassette->redaction->redact($placeholder, $value);
    }

    /**
     * @param  (callable(): mixed)|null  $value  without it the rule is write-only: the header
     *                                           is stored redacted, the code under test sees
     *                                           the placeholder on replay, and the header
     *                                           stops distinguishing interactions (§3.3)
     */
    public function redactHeader(string $name, ?callable $value = null): void
    {
        $this->configuring('redactHeader');

        $this->cassette->redaction->redactHeader($name, $value);
    }

    /**
     * @param  string  $pointer  a JSON Pointer into the body: `/customer/email`
     * @param  (callable(): mixed)|null  $value
     */
    public function redactJsonField(string $pointer, ?callable $value = null): void
    {
        $this->configuring('redactJsonField');

        $this->cassette->redaction->redactJsonField($pointer, $value);
    }

    /**
     * @param  (callable(): mixed)|null  $value
     */
    public function redactQueryParam(string $name, ?callable $value = null): void
    {
        $this->configuring('redactQueryParam');

        $this->cassette->redaction->redactQueryParam($name, $value);
    }

    /**
     * @param  (callable(): mixed)|null  $value
     */
    public function redactFormField(string $name, ?callable $value = null): void
    {
        $this->configuring('redactFormField');

        $this->cassette->redaction->redactFormField($name, $value);
    }

    /**
     * Takes one of the four automatically redacted headers — Authorization,
     * Proxy-Authorization, Cookie, Set-Cookie — back out of redaction, for a test that
     * verifies the header itself. It starts distinguishing interactions again too (§3.3).
     *
     * @param  list<string>  $names
     */
    public function includeSensitiveHeaders(array $names): void
    {
        $this->configuring('includeSensitiveHeaders');

        $this->cassette->redaction->includeSensitiveHeaders($names);
    }

    /**
     * Registers a hook that sees each interaction on its way to the cassette, and may
     * change it or return null to keep it out of the file altogether (§3.5).
     *
     * @param  callable(Interaction): ?Interaction  $hook
     */
    public function beforeRecord(callable $hook): void
    {
        $this->configuring('beforeRecord');

        $this->cassette->hooks->addBeforeRecord($hook);
    }

    /**
     * Registers a hook that sees each recorded interaction on its way back out — before
     * the matchers compare anything, so a request changed here is the one matching sees.
     *
     * @param  callable(Interaction): Interaction  $hook
     */
    public function beforePlayback(callable $hook): void
    {
        $this->configuring('beforePlayback');

        $this->cassette->hooks->addBeforePlayback($hook);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->cassette->begin();

        [$request, $incoming] = $this->snapshot($request);

        // Which file this request belongs in is settled before anything is compared: with a
        // scope resolver, one cassette name is several files and they never see each other's
        // interactions (§3.8).
        $cassette = $this->cassette->for($request);
        $interaction = $cassette->play($incoming);

        if ($interaction?->response !== null) {
            return $this->rebuild($interaction->response);
        }

        if ($interaction?->error !== null) {
            throw match ($interaction->error->category) {
                ErrorCategory::Network => VcrNetworkException::replaying($interaction->error, $request),
                ErrorCategory::Request => VcrRequestException::replaying($interaction->error, $request),
            };
        }

        return $this->recordOrExplain($cassette, $request, $incoming);
    }

    /**
     * Ends the cassette session: releases the lock a recording session holds, and checks
     * whatever {@see StrictMode} the cassette was opened with (§3.6).
     *
     * Worth calling from a test harness, which knows when the test is over and shouldn't
     * wait for a garbage collector to release a lock other processes are queueing behind.
     * The destructor gives the lock back on its own, but never raises a strict-mode
     * failure — an assertion belongs at a moment the test chose.
     */
    public function close(): void
    {
        $this->cassette->close();
    }

    public function __destruct()
    {
        if ($this->ownsSession) {
            $this->cassette->release();
        }
    }

    /**
     * Configuration is only configuration until the session has started; after that it is
     * a change of rules halfway through, covering some interactions and not others.
     */
    private function configuring(string $method): void
    {
        if (! $this->cassette->hasStarted()) {
            return;
        }

        throw new LogicException(sprintf(
            '%s() has to be called before the first request of this cassette session. An interaction has '
            .'already been through the pipeline it configures, so registering it now would cover part of '
            .'the run and not the rest.',
            $method,
        ));
    }

    private function recordOrExplain(
        CassetteManager $cassette,
        RequestInterface $request,
        RecordedRequest $incoming,
    ): ResponseInterface {
        $blocked = $cassette->recordingBlockedBecause();
        $scope = $cassette->cassetteExists() ? null : $cassette->scope();

        // Named first even when the cassette is missing too: with recording allowed, this
        // very run would have recorded and passed, so that is the actual cause.
        if ($blocked !== null) {
            throw $scope === null
                ? RecordingNotAllowedException::forRequest($incoming, $cassette->location(), $blocked)
                : RecordingNotAllowedException::forScope(
                    $cassette->name(),
                    $scope,
                    $cassette->existingScopes(),
                    $blocked,
                );
        }

        if ($cassette->isRecording()) {
            return $this->record($cassette, $request, $incoming);
        }

        if (! $cassette->cassetteExists()) {
            throw $scope === null
                ? CassetteNotFoundException::at($cassette->location(), $incoming)
                : CassetteNotFoundException::forScope(
                    $cassette->name(),
                    $scope,
                    $cassette->existingScopes(),
                    $cassette->mode(),
                );
        }

        throw NoMatchingInteractionException::forRequest(
            $incoming,
            $cassette->location(),
            $cassette->mismatches($incoming),
            $cassette->interactionCount(),
        );
    }

    private function record(
        CassetteManager $cassette,
        RequestInterface $request,
        RecordedRequest $incoming,
    ): ResponseInterface {
        if ($this->inner === null) {
            throw new LogicException(
                'This VcrClient was built without an inner client, so it can only replay. '
                .'Pass the real PSR-18 client to the constructor to record with it.',
            );
        }

        $inner = $this->inner;

        $this->requireCredentials($cassette->name(), $incoming);

        try {
            $response = $inner->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            $this->recordFailure($cassette, $incoming, $exception);

            throw $exception;
        }

        [$response, $body] = $this->decompress(...$this->buffer($response));

        $cassette->record($incoming, new RecordedResponse(
            $response->getStatusCode(),
            $this->headers($response),
            $body,
            $this->encoding($response, $body),
        ));

        return $response;
    }

    /**
     * Checks the variables this recording needs, at the one moment that can tell whether it
     * needs them: a request about to go out for real (§3.12).
     *
     * Not at the start of the test — recording is allowed by default on a developer's
     * machine, so that would have every replaying test there demand a full set of
     * credentials it was never going to use. And per request, not per session, which is
     * what lets a partial re-record of a two-API cassette ask only for the keys of the API
     * it is refreshing.
     */
    private function requireCredentials(string $cassette, RecordedRequest $request): void
    {
        $host = parse_url($request->uri, PHP_URL_HOST);
        $provider = is_string($host) ? $this->config->providerFor($host) : null;
        $missing = [];

        if ($provider !== null) {
            $names = $this->environment->missing($this->config->providers()[$provider]->requiresEnv);

            if ($names !== []) {
                $missing[] = ['names' => $names, 'source' => sprintf('provider "%s"', $provider)];
            }
        }

        $names = $this->environment->missing($this->requiresEnv);

        if ($names !== []) {
            $missing[] = ['names' => $names, 'source' => 'the cassette'];
        }

        if ($missing !== []) {
            throw MissingEnvironmentVariableException::beforeRecording($cassette, $missing);
        }
    }

    /**
     * A transport failure is only persisted when the cassette asked for it: a transient
     * network blip shouldn't become a permanent part of a regression test. Either way the
     * original exception continues on its way to the code under test, unchanged.
     *
     * Only the two failures PSR-18 gives an interface to are recordable — those are the two
     * that can be replayed as something an application catching by contract will recognize.
     * A client exception that is neither passes through unrecorded.
     */
    private function recordFailure(
        CassetteManager $cassette,
        RecordedRequest $incoming,
        ClientExceptionInterface $exception,
    ): void {
        $category = match (true) {
            $exception instanceof NetworkExceptionInterface => ErrorCategory::Network,
            $exception instanceof RequestExceptionInterface => ErrorCategory::Request,
            default => null,
        };

        if (! $this->recordTransportErrors || $category === null) {
            return;
        }

        $cassette->recordFailure($incoming, new RecordedError(
            $category,
            $exception->getMessage(),
            $exception::class,
        ));
    }

    /**
     * @return array{RequestInterface, RecordedRequest} the request to carry on with, which
     *                                                  is a new object when its body had to
     *                                                  be buffered, and its snapshot
     */
    private function snapshot(RequestInterface $request): array
    {
        [$request, $body] = $this->buffer($request);

        return [$request, new RecordedRequest(
            $request->getMethod(),
            (string) $request->getUri(),
            $this->headers($request),
            $body,
            $this->encoding($request, $body),
        )];
    }

    /**
     * A compressed body is stored decompressed, with Content-Encoding stripped.
     *
     * Two reasons, and the second is the one that would hurt: redaction works on text and
     * has nothing to say about a gzip frame, and a cassette is meant to be read in a pull
     * request. The decompressed response is what the code under test receives on this run
     * too, not just on replay — a recording run and a replaying run have to hand back the
     * same thing, or a test passes once and fails after.
     *
     * Content this build cannot decompress — brotli without the extension — is stored as it
     * came, header and all, so replay is still faithful and the client's own decoding still
     * applies.
     *
     * @return array{ResponseInterface, string}
     */
    private function decompress(ResponseInterface $response, string $body): array
    {
        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));

        if (! $this->decodeCompressedResponse || $encoding === '' || $encoding === 'identity' || $body === '') {
            return [$response, $body];
        }

        $decoded = $this->inflate($encoding, $body);

        if ($decoded === null) {
            return [$response, $body];
        }

        $response = $response->withoutHeader('Content-Encoding');

        if ($response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', (string) strlen($decoded));
        }

        return [$response->withBody($this->streamFactory->createStream($decoded)), $decoded];
    }

    private function inflate(string $encoding, string $body): ?string
    {
        $decoded = match ($encoding) {
            'gzip', 'x-gzip' => function_exists('gzdecode') ? @gzdecode($body) : false,
            'deflate' => $this->inflateDeflate($body),
            'br' => function_exists('brotli_uncompress') ? @brotli_uncompress($body) : false,
            default => false,
        };

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * `deflate` is two things in the wild: zlib-wrapped, as the RFC says, and raw, as some
     * servers send it. Try the correct one, fall back to the common mistake.
     */
    private function inflateDeflate(string $body): string|false
    {
        if (! function_exists('gzuncompress')) {
            return false;
        }

        $decoded = @gzuncompress($body);

        return $decoded === false ? @gzinflate($body) : $decoded;
    }

    /**
     * How this body has to be stored: as text, or base64-encoded because it is bytes.
     *
     * Decided from Content-Type, with the actual content as the tie-breaker — a body that
     * claims to be text but isn't valid UTF-8 is bytes whatever the header says. A hook can
     * override the result before the interaction is written.
     */
    private function encoding(MessageInterface $message, string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $type = strtolower(trim(explode(';', $message->getHeaderLine('Content-Type'))[0]));

        $textual = $type === ''
            || str_starts_with($type, 'text/')
            || $type === 'application/json'
            || $type === 'application/x-www-form-urlencoded'
            || (str_starts_with($type, 'application/') && str_ends_with($type, '+json'));

        return $textual && preg_match('//u', $body) === 1 ? null : 'base64';
    }

    /**
     * PSR-7 promises a list of values per header name, but not the array shape the
     * snapshots are typed with.
     *
     * @return array<string, list<string>>
     */
    private function headers(MessageInterface $message): array
    {
        $headers = [];

        foreach ($message->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        return $headers;
    }

    private function rebuild(RecordedResponse $recorded): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($recorded->status);

        foreach ($recorded->headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withBody($this->streamFactory->createStream($recorded->body));
    }

    /**
     * A body is read once, at the edge, and everything downstream works on the string — a
     * PSR-7 stream is mutable, so reading it twice is not the same as reading it once.
     *
     * A seekable stream is rewound afterwards and the message comes back unchanged. A
     * stream that cannot be rewound is spent by the very act of recording it, so its
     * content is buffered and put back as a fresh stream: without that substitution the
     * inner client would send an empty body, or the code under test would receive a
     * response it cannot read.
     *
     * @template T of MessageInterface
     *
     * @param  T  $message
     * @return array{T, string}
     */
    private function buffer(MessageInterface $message): array
    {
        $stream = $message->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
            $content = $stream->getContents();
            $stream->rewind();

            return [$message, $content];
        }

        $content = $stream->getContents();
        $buffered = $message->withBody($this->streamFactory->createStream($content));

        return [$buffered, $content];
    }
}
