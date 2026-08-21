<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\Guzzle;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use HttpVcr\VcrClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Record and replay for a Guzzle handler stack (§3.9).
 *
 * `GuzzleHttp\Client` implements PSR-18, so wrapping it in a {@see VcrClient} works — but
 * only for the calls that actually go through `sendRequest()`. Guzzle's own API
 * (`request()`, `get()`/`post()`, `requestAsync()`, `Pool`) goes straight to the handler
 * stack and past any decorator around the client, which is a real request to a real API
 * with no cassette involved and nothing said about it. This middleware sits in the stack
 * itself, below every one of those entry points.
 *
 * It holds no record/replay logic of its own. It translates the shape of the call, and
 * hands VcrClient the next handler as the transport to record through — so a real request
 * goes down the rest of the stack (retry, logging) instead of around it, and passing the
 * very client this stack belongs to can't loop back through the middleware.
 */
final class VcrMiddleware
{
    /**
     * The middleware to hand `HandlerStack::push()`.
     *
     * Guzzle applies the stack from the bottom up, so whatever is pushed after this sits
     * between the cassette and the transport and sees real requests only; whatever was
     * pushed before it — including everything `HandlerStack::create()` comes with — sits
     * above and treats a replayed response exactly like one off the wire.
     *
     * @return Closure(callable): Closure
     */
    public static function create(VcrClient $vcr): Closure
    {
        return static fn (callable $handler): Closure =>
            static function (RequestInterface $request, array $options) use ($vcr, $handler): PromiseInterface {
                try {
                    return Create::promiseFor(
                        $vcr->withInner(self::transport($handler, $options))->sendRequest($request),
                    );
                } catch (Throwable $failure) {
                    // A rejected promise rather than a throw: a middleware that throws
                    // synchronously breaks requestAsync(), which promises to hand back a
                    // promise. Guzzle turns the rejection back into this same exception for
                    // the synchronous callers, so nothing else changes.
                    return Create::rejectionFor($failure);
                }
            };
    }

    /**
     * The next handler in the stack, as the PSR-18 client VcrClient records through.
     *
     * @param array<array-key, mixed> $options the options of this request, carried on to the
     *                                      transport unchanged — the ones that only make
     *                                      sense for a real connection (timeout, proxy,
     *                                      sink, on_stats) simply have nothing to apply to
     *                                      when the response comes off a cassette
     */
    private static function transport(callable $handler, array $options): ClientInterface
    {
        return new class ($handler(...), $options) implements ClientInterface {
            /**
             * @param array<array-key, mixed> $options
             */
            public function __construct(
                private readonly Closure $handler,
                private readonly array $options,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $response = ($this->handler)($request, $this->options);

                if ($response instanceof PromiseInterface) {
                    $response = $response->wait();
                }

                if (!$response instanceof ResponseInterface) {
                    throw new RuntimeException(sprintf(
                        'The handler below http-vcr in the stack produced %s instead of a response.',
                        get_debug_type($response),
                    ));
                }

                return $response;
            }
        };
    }
}
