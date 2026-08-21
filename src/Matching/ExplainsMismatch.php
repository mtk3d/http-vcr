<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;

/**
 * A matcher that can say *why* it rejected an interaction, for the
 * NoMatchingInteractionException message.
 *
 * Kept apart from {@see RequestMatcherInterface} so writing a custom matcher stays a
 * one-method job; a matcher that doesn't implement this is simply reported by name.
 */
interface ExplainsMismatch
{
    /**
     * @return string|null a short expected-vs-actual comparison, or null when there is
     *                     nothing useful to add beyond the matcher's name
     */
    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): ?string;
}
