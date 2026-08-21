<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use HttpVcr\Cassette\RecordedError;

/**
 * Shared wording for the two replayed transport failures.
 *
 * The original message comes first and unchanged — code under test may well assert on it —
 * with the provenance appended, so nobody debugging has to wonder why a connection refused
 * itself on a machine that made no connection.
 *
 * @internal
 */
final class ReplayedFailure
{
    public static function describe(RecordedError $error): string
    {
        $message = $error->message === '' ? 'Recorded transport failure' : $error->message;

        return $error->exceptionClass === ''
            ? sprintf('%s (replayed from a cassette)', $message)
            : sprintf('%s (replayed from a cassette, recorded as %s)', $message, $error->exceptionClass);
    }
}
