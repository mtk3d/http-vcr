<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;

/**
 * Compares the HTTP method, case-insensitively — methods are uppercase by convention,
 * and a client that spells one differently still means the same method.
 */
final class MethodMatcher implements RequestMatcherInterface, ExplainsMismatch
{
    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
    {
        return strcasecmp($recorded->method, $incoming->method) === 0;
    }

    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): string
    {
        return sprintf('expected %s, got %s', $recorded->method, $incoming->method);
    }
}
