<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;

/**
 * Compares request bodies byte for byte. Two bodies that differ only in whitespace are
 * different requests here — {@see BodyJsonMatcher} is the one that reads JSON as JSON.
 */
final class BodyMatcher implements RequestMatcherInterface, ExplainsMismatch
{
    private const EXCERPT_LENGTH = 60;

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

        return sprintf('expected "%s", got "%s"', $this->excerpt($recorded->body), $this->excerpt($incoming->body));
    }

    /**
     * The first characters of a body, cut on a character boundary rather than a byte one —
     * without reaching for mbstring, which the core deliberately doesn't depend on.
     */
    private function excerpt(string $body): string
    {
        if (preg_match('/^.{0,' . self::EXCERPT_LENGTH . '}/us', $body, $match) === 1) {
            return $match[0] === $body ? $body : $match[0] . '…';
        }

        return strlen($body) > self::EXCERPT_LENGTH ? substr($body, 0, self::EXCERPT_LENGTH) . '…' : $body;
    }
}
