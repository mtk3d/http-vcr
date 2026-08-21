<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

/**
 * What came back — a response, or nothing at all.
 *
 * A 4xx or 5xx is an ordinary Success: the request got an answer. Error is the case where
 * PSR-18 has no response to hand over and throws instead.
 */
enum Outcome: string
{
    case Success = 'success';
    case Error = 'error';
}
