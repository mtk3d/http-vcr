<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;

/**
 * Compares request bodies byte for byte. Two bodies that differ only in whitespace are
 * different requests here — {@see BodyJsonMatcher} is the one that reads JSON as JSON.
 */
final class BodyMatcher implements ExplainsMismatch, RequestMatcherInterface
{
    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
    {
        return $recorded->body === $incoming->body;
    }

    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): ?string
    {
        if ($this->matches($recorded, $incoming)) {
            return null;
        }

        // Bytes in a diagnostic message help nobody, and a terminal even less.
        if ($recorded->bodyEncoding !== null || $incoming->bodyEncoding !== null) {
            return sprintf(
                'binary body: expected %d bytes, got %d',
                strlen($recorded->body),
                strlen($incoming->body),
            );
        }

        return sprintf('expected "%s", got "%s"', Excerpt::of($recorded->body), Excerpt::of($incoming->body));
    }
}
