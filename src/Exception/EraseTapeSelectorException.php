<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use InvalidArgumentException;

/**
 * A `VCR_ERASE_TAPE` value that cannot mean anything (§3.1).
 *
 * Everything this refuses is refused at parse time, before a cassette is opened, because
 * the alternative is the failure mode this variable can least afford: a selector that
 * selects nothing looks exactly like a run with nothing to re-record, and the person who
 * typed it goes on believing the recording was refreshed.
 *
 * Extends `InvalidArgumentException` — that is what a malformed argument is, and it is
 * what this was before it had a name of its own.
 */
final class EraseTapeSelectorException extends InvalidArgumentException implements VcrException
{
    public static function bareBoolean(string $selector): self
    {
        return new self(sprintf(
            'VCR_ERASE_TAPE takes cassette selectors, not "%s". Name the cassette to re-record '
            .'("shopify/get-product"), the API ("@shopify"), or every cassette the run opens ("all").',
            $selector,
        ));
    }

    public static function noApiAfterAt(string $selector): self
    {
        return new self(sprintf('VCR_ERASE_TAPE selector "%s" names no API after "@".', $selector));
    }

    /**
     * @param  list<string>  $configured  the provider names this project has declared
     */
    public static function unknownProvider(string $name, array $configured): self
    {
        return new self(sprintf(
            'VCR_ERASE_TAPE selector "@%s" names no configured provider and is not a hostname. %s',
            $name,
            $configured === []
                ? 'No providers are configured, so an API can only be named by its host ("@api.stripe.com").'
                : 'Configured providers: '.implode(', ', $configured).'.',
        ));
    }
}
