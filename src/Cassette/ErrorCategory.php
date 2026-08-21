<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

/**
 * Which PSR-18 exception interface the original failure implemented, and therefore which
 * one replaying it has to satisfy.
 */
enum ErrorCategory: string
{
    case Network = 'network';
    case Request = 'request';
}
