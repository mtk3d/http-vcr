<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;

/**
 * Combines matchers with AND: every one of them has to accept.
 *
 * Also the place that knows which matcher turned an interaction down first, which is what
 * the NoMatchingInteractionException message is built from — evaluation stops there, so a
 * request rejected on method or URI doesn't drag in opinions from matchers that never got
 * to see the rest of it.
 */
final class CompositeMatcher implements RequestMatcherInterface
{
    /** @var list<RequestMatcherInterface> */
    private array $matchers;

    public function __construct(RequestMatcherInterface ...$matchers)
    {
        $this->matchers = array_values($matchers);
    }

    /**
     * @param list<RequestMatcherInterface> $matchers
     */
    public static function of(array $matchers): self
    {
        return new self(...$matchers);
    }

    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool
    {
        return $this->explainMismatch($recorded, $incoming) === null;
    }

    /**
     * @return Mismatch|null null when every matcher accepted
     */
    public function explainMismatch(RecordedRequest $recorded, RecordedRequest $incoming): ?Mismatch
    {
        foreach ($this->matchers as $matcher) {
            if ($matcher->matches($recorded, $incoming)) {
                continue;
            }

            return Mismatch::from(
                $matcher,
                $matcher instanceof ExplainsMismatch ? $matcher->explainMismatch($recorded, $incoming) : null,
            );
        }

        return null;
    }
}
