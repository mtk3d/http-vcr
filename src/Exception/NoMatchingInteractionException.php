<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Matching\Mismatch;
use RuntimeException;

/**
 * The cassette exists, but nothing in it still available matches the incoming request.
 */
class NoMatchingInteractionException extends RuntimeException implements VcrException
{
    /**
     * @param  array<int, Mismatch>  $mismatches  the first matcher to reject each unconsumed
     *                                            interaction, keyed by its position in the
     *                                            cassette, counting from 1
     */
    public static function forRequest(
        RecordedRequest $incoming,
        string $cassetteLocation,
        array $mismatches,
        int $interactionCount,
    ): self {
        $message = sprintf(
            "No matching interaction for %s %s\n\nCassette %s, ",
            $incoming->method,
            $incoming->uri,
            $cassetteLocation,
        );

        if ($mismatches === []) {
            return new self($message.sprintf(
                '%s already consumed.',
                $interactionCount === 1 ? 'its one interaction was' : "all {$interactionCount} interactions were",
            ));
        }

        $lines = [];

        foreach ($mismatches as $position => $mismatch) {
            $lines[] = sprintf('  #%d  %s', $position, $mismatch->describe());
        }

        return new self($message.sprintf(
            "%d unconsumed interaction%s:\n%s",
            count($lines),
            count($lines) === 1 ? '' : 's',
            implode("\n", $lines),
        ));
    }
}
