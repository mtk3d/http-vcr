<?php

declare(strict_types=1);

namespace HttpVcr\Exception;

use Throwable;

/**
 * Implemented by everything http-vcr throws on purpose, so a test that only wants to know
 * "the VCR layer refused" can catch this one type.
 *
 * An interface rather than a base class because the replayed transport failures have to be
 * PSR-18 exceptions at the same time.
 */
interface VcrException extends Throwable {}
