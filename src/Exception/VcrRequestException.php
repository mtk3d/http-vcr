<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use HttpVcr\Cassette\RecordedError;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A recorded request failure — a request the client refused to send — played back.
 *
 * The RequestExceptionInterface counterpart of {@see VcrNetworkException}; the same
 * reasoning about not reconstructing the original class applies.
 */
final class VcrRequestException extends RuntimeException implements RequestExceptionInterface, VcrException
{
    private function __construct(string $message, private readonly RequestInterface $request)
    {
        parent::__construct($message);
    }

    public static function replaying(RecordedError $error, RequestInterface $request): self
    {
        return new self(ReplayedFailure::describe($error), $request);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
