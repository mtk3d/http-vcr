<?php

declare(strict_types=1);

namespace HttpVcr;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Cassette\ErrorCategory;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedError;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Exception\CassetteNotFoundException;
use HttpVcr\Exception\NoMatchingInteractionException;
use HttpVcr\Exception\RecordingNotAllowedException;
use HttpVcr\Exception\VcrNetworkException;
use HttpVcr\Exception\VcrRequestException;
use HttpVcr\Matching\CompositeMatcher;
use HttpVcr\Matching\RequestMatcherInterface;
use HttpVcr\Persistence\CassettePersisterInterface;
use HttpVcr\Serializer\CassetteSerializerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A PSR-18 client that replays a cassette instead of making requests — and records one
 * when there is nothing to replay yet.
 *
 * A decorator, not a patch: nothing here touches curl, a stream wrapper, or anything
 * outside this object, so two instances in one process never interfere.
 */
final class VcrClient implements ClientInterface
{
    private readonly CassetteManager $cassette;

    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param ClientInterface|null          $inner    the real client, used only when actually recording
     * @param list<RequestMatcherInterface> $matchers empty means the project default set
     */
    public function __construct(
        private readonly ?ClientInterface $inner,
        string $cassette,
        RecordMode $mode = RecordMode::RecordIfAbsent,
        array $matchers = [],
        private readonly bool $recordTransportErrors = false,
        private readonly bool $decodeCompressedResponse = true,
        ?int $inlineBodyLimit = null,
        bool $repeatablePlayback = false,
        bool $locked = false,
        ?CassettePersisterInterface $persister = null,
        ?CassetteSerializerInterface $serializer = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?ClockInterface $clock = null,
    ) {
        Config::freeze();
        $config = Config::global();

        $factories = new Psr17FactoryResolver(array_filter([
            ResponseFactoryInterface::class => $responseFactory,
            StreamFactoryInterface::class => $streamFactory,
        ]) + $config->psr17Factories());

        $this->responseFactory = $factories->responseFactory();
        $this->streamFactory = $factories->streamFactory();

        $this->cassette = new CassetteManager(
            $cassette,
            $persister ?? $config->persister(),
            $serializer ?? $config->serializer(),
            CompositeMatcher::of($matchers !== [] ? $matchers : $config->defaultMatchers()),
            $clock ?? $config->clock(),
            Environment::fromSystem(),
            $mode,
            $repeatablePlayback,
            $locked,
            $inlineBodyLimit ?? $config->inlineBodyLimit(),
            scanner: $config->scanRecordingsForSecrets() ? new SecretScanner() : null,
        );
    }

    /**
     * Project-wide defaults, for a bootstrap that would rather write code than a config
     * file. Has to run before the first VcrClient of the process exists; afterwards it
     * throws, so that two tests in one process can't see different defaults depending on
     * the order they ran in.
     *
     * @param list<RequestMatcherInterface> $defaultMatchers
     */
    public static function configure(
        ?string $cassetteDirectory = null,
        ?CassettePersisterInterface $persister = null,
        ?CassetteSerializerInterface $serializer = null,
        array $defaultMatchers = [],
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?ClockInterface $clock = null,
        ?bool $scanRecordingsForSecrets = null,
    ): void {
        Config::replaceGlobal(Config::create(
            $cassetteDirectory,
            $persister,
            $serializer,
            $defaultMatchers,
            $responseFactory,
            $streamFactory,
            $clock,
            scanRecordingsForSecrets: $scanRecordingsForSecrets,
        ));
    }

    /**
     * Replaces a secret with $placeholder everywhere it appears in an interaction, in both
     * directions: the placeholder goes to disk, and the real value comes back on replay —
     * into the recorded request before matching compares it, and into the response before
     * the code under test receives it (§3.4).
     *
     * @param callable(): mixed $value read when it is needed, not when this is called, so
     *                                 a test may set the variable it reads afterwards
     */
    public function redact(string $placeholder, callable $value): void
    {
        $this->configuring('redact');

        $this->cassette->redaction->redact($placeholder, $value);
    }

    /**
     * @param (callable(): mixed)|null $value without it the rule is write-only: the header
     *                                        is stored redacted, the code under test sees
     *                                        the placeholder on replay, and the header
     *                                        stops distinguishing interactions (§3.3)
     */
    public function redactHeader(string $name, ?callable $value = null): void
    {
        $this->configuring('redactHeader');

        $this->cassette->redaction->redactHeader($name, $value);
    }

    /**
     * @param string                   $pointer a JSON Pointer into the body: `/customer/email`
     * @param (callable(): mixed)|null $value
     */
    public function redactJsonField(string $pointer, ?callable $value = null): void
    {
        $this->configuring('redactJsonField');

        $this->cassette->redaction->redactJsonField($pointer, $value);
    }

    /**
     * @param (callable(): mixed)|null $value
     */
    public function redactQueryParam(string $name, ?callable $value = null): void
    {
        $this->configuring('redactQueryParam');

        $this->cassette->redaction->redactQueryParam($name, $value);
    }

    /**
     * @param (callable(): mixed)|null $value
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
     * @param list<string> $names
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
     * @param callable(Interaction): ?Interaction $hook
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
     * @param callable(Interaction): Interaction $hook
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
        $interaction = $this->cassette->play($incoming);

        if ($interaction?->response !== null) {
            return $this->rebuild($interaction->response);
        }

        if ($interaction?->error !== null) {
            throw match ($interaction->error->category) {
                ErrorCategory::Network => VcrNetworkException::replaying($interaction->error, $request),
                ErrorCategory::Request => VcrRequestException::replaying($interaction->error, $request),
            };
        }

        return $this->recordOrExplain($request, $incoming);
    }

    /**
     * Ends the cassette session, releasing the lock a recording session holds.
     *
     * Called automatically when the client is collected; worth calling explicitly from a
     * test harness, which knows when the test is over and shouldn't wait for a garbage
     * collector to release a lock other processes are queueing behind.
     */
    public function close(): void
    {
        $this->cassette->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Configuration is only configuration until the session has started; after that it is
     * a change of rules halfway through, covering some interactions and not others.
     */
    private function configuring(string $method): void
    {
        if (!$this->cassette->hasStarted()) {
            return;
        }

        throw new LogicException(sprintf(
            '%s() has to be called before the first request of this cassette session. An interaction has '
            . 'already been through the pipeline it configures, so registering it now would cover part of '
            . 'the run and not the rest.',
            $method,
        ));
    }

    private function recordOrExplain(RequestInterface $request, RecordedRequest $incoming): ResponseInterface
    {
        $blocked = $this->cassette->recordingBlockedBecause();

        // Named first even when the cassette is missing too: with recording allowed, this
        // very run would have recorded and passed, so that is the actual cause.
        if ($blocked !== null) {
            throw RecordingNotAllowedException::forRequest($incoming, $this->cassette->location(), $blocked);
        }

        if ($this->cassette->isRecording()) {
            return $this->record($request, $incoming);
        }

        if (!$this->cassette->cassetteExists()) {
            throw CassetteNotFoundException::at($this->cassette->location(), $incoming);
        }

        throw NoMatchingInteractionException::forRequest(
            $incoming,
            $this->cassette->location(),
            $this->cassette->mismatches($incoming),
            $this->cassette->interactionCount(),
        );
    }

    private function record(RequestInterface $request, RecordedRequest $incoming): ResponseInterface
    {
        if ($this->inner === null) {
            throw new LogicException(
                'This VcrClient was built without an inner client, so it can only replay. '
                . 'Pass the real PSR-18 client to the constructor to record with it.',
            );
        }

        try {
            $response = $this->inner->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            $this->recordFailure($incoming, $exception);

            throw $exception;
        }

        [$response, $body] = $this->decompress(...$this->buffer($response));

        $this->cassette->record($incoming, new RecordedResponse(
            $response->getStatusCode(),
            $this->headers($response),
            $body,
            $this->encoding($response, $body),
        ));

        return $response;
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
    private function recordFailure(RecordedRequest $incoming, ClientExceptionInterface $exception): void
    {
        $category = match (true) {
            $exception instanceof NetworkExceptionInterface => ErrorCategory::Network,
            $exception instanceof RequestExceptionInterface => ErrorCategory::Request,
            default => null,
        };

        if (!$this->recordTransportErrors || $category === null) {
            return;
        }

        $this->cassette->recordFailure($incoming, new RecordedError(
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

        if (!$this->decodeCompressedResponse || $encoding === '' || $encoding === 'identity' || $body === '') {
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
        if (!function_exists('gzuncompress')) {
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
     * @param T $message
     *
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
