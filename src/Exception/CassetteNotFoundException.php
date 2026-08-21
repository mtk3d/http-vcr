<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use HttpVcr\Cassette\RecordedRequest;

/**
 * There is no cassette file at all — nothing was ever recorded under that name, and this
 * run isn't allowed to record it.
 *
 * A specialization of {@see NoMatchingInteractionException}: code that doesn't care why
 * nothing came back catches the parent, code that wants to tell "never recorded" from
 * "recorded, but this request isn't in it" catches this one.
 */
final class CassetteNotFoundException extends NoMatchingInteractionException
{
    public static function at(string $cassetteLocation, RecordedRequest $incoming): self
    {
        return new self(sprintf(
            'No cassette at %s to replay %s %s from.',
            $cassetteLocation,
            $incoming->method,
            $incoming->uri,
        ));
    }
}
