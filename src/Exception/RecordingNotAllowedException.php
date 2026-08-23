<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Environment;
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
     * @param  string  $cause  why recording is disabled, named precisely enough to act on —
     *                         see {@see Environment::recordingBlockedBecause()}
     */
    public static function forErasedCassette(string $cassetteName, string $cause): self
    {
        return new self(sprintf(
            'Recording is disabled by %s, ignoring VCR_ERASE_TAPE — cassette "%s" was left alone rather '
            .'than erased with no way to record it again.',
            $cause,
            $cassetteName,
        ));
    }

    /**
     * The scoped twin of forRequest(): the file for the computed scope is missing and this
     * run may not record it. Still this exception rather than "no cassette for that scope",
     * because the same run with recording allowed would have recorded the new scope and
     * passed (§3.8).
     *
     * @param  list<string>  $existingScopes
     */
    public static function forScope(
        string $cassetteName,
        string $scope,
        array $existingScopes,
        string $cause,
    ): self {
        return new self(sprintf(
            'Cannot record cassette "%s" (scope "%s"): recording is disabled by %s. %s',
            $cassetteName,
            $scope,
            $cause,
            CassetteNotFoundException::describeScopes($existingScopes),
        ));
    }

    public static function forRequest(RecordedRequest $incoming, string $cassetteLocation, string $cause): self
    {
        return new self(sprintf(
            "Recording is disabled by %s, so %s %s could not be recorded into %s.\n"
            .'Set VCR_ALLOW_RECORDING=1 to record it.',
            $cause,
            $incoming->method,
            $incoming->uri,
            $cassetteLocation,
        ));
    }
}
