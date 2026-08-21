<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\RecordMode;

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

    /**
     * The cassette exists under other scopes, just not this one — the version bump from
     * §3.8, where listing the scopes on disk is what actually answers the question.
     *
     * @param list<string> $existingScopes
     */
    public static function forScope(
        string $cassetteName,
        string $scope,
        array $existingScopes,
        RecordMode $mode,
    ): self {
        return new self(sprintf(
            'No cassette recorded for scope "%s" (base: %s). %s Mode is %s, which never records — '
            . 'record it under RecordIfAbsent, or add the missing scope by hand.',
            $scope,
            $cassetteName,
            self::describeScopes($existingScopes),
            $mode->name,
        ));
    }

    /**
     * @param list<string> $existingScopes
     */
    public static function describeScopes(array $existingScopes): string
    {
        return $existingScopes === []
            ? 'No scope of it has been recorded yet.'
            : sprintf('Existing scopes: %s.', implode(', ', $existingScopes));
    }
}
