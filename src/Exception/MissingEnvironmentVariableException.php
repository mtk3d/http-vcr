<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use RuntimeException;

/**
 * A variable recording this request needs has no value (§3.12).
 *
 * Raised on the recording branch and before the request goes out, so the failure names the
 * missing credential rather than arriving later as a 401 halfway through a test.
 */
final class MissingEnvironmentVariableException extends RuntimeException implements VcrException
{
    /**
     * @param list<array{names: list<string>, source: string}> $missing what is missing, and
     *                                                                 which declaration asked for it
     */
    public static function beforeRecording(string $cassette, array $missing): self
    {
        $parts = [];

        foreach ($missing as $group) {
            $parts[] = sprintf(
                'missing env var%s %s (required by %s)',
                count($group['names']) === 1 ? '' : 's',
                implode(', ', $group['names']),
                $group['source'],
            );
        }

        return new self(sprintf('Cannot record cassette "%s": %s.', $cassette, implode(', ', $parts)));
    }
}
