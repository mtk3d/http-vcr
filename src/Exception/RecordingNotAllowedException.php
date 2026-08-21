<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use HttpVcr\Cassette\RecordedRequest;
use RuntimeException;

/**
 * Something needed recording and VCR_ALLOW_RECORDING stood in the way.
 *
 * This takes precedence over "no cassette" and "nothing matched" in the message, because
 * it is the actual cause: the same run with recording allowed would have succeeded.
 */
final class RecordingNotAllowedException extends RuntimeException implements VcrException
{
    /**
     * @param string $cause why recording is disabled, named precisely enough to act on —
     *                      see {@see \HttpVcr\Environment::recordingBlockedBecause()}
     */
    public static function forRequest(RecordedRequest $incoming, string $cassetteLocation, string $cause): self
    {
        return new self(sprintf(
            "Recording is disabled by %s, so %s %s could not be recorded into %s.\n"
            . 'Set VCR_ALLOW_RECORDING=1 to record it.',
            $cause,
            $incoming->method,
            $incoming->uri,
            $cassetteLocation,
        ));
    }
}
