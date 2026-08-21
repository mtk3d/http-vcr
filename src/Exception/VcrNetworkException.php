<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use HttpVcr\Cassette\RecordedError;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A recorded network failure — timeout, DNS, connection refused — played back.
 *
 * Implements two things at once: PSR-18's NetworkExceptionInterface, so application error
 * handling written against the PSR-18 contract behaves exactly as it does against a real
 * failure, and VcrException, so a test catching the VCR layer broadly still catches it.
 *
 * Deliberately not the original client's exception class: PSR-18 standardizes the
 * interfaces, not the constructors, so there is no safe general way to rebuild an arbitrary
 * library's exception from stored data. The original class name is carried in the message
 * as diagnostics.
 */
final class VcrNetworkException extends RuntimeException implements NetworkExceptionInterface, VcrException
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
