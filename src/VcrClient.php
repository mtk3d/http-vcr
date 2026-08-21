<?php

declare(strict_types=1);

namespace HttpVcr;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Cassette\ErrorCategory;
use HttpVcr\Cassette\RecordedError;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Clock\ClockInterface;
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
    ): void {
        Config::replaceGlobal(Config::create(
            $cassetteDirectory,
            $persister,
            $serializer,
            $defaultMatchers,
            $responseFactory,
            $streamFactory,
            $clock,
        ));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
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

        [$response, $body] = $this->buffer($response);

        $this->cassette->record($incoming, new RecordedResponse(
            $response->getStatusCode(),
            $this->headers($response),
            $body,
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
        )];
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
