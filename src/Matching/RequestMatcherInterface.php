<?php

declare(strict_types=1);

namespace HttpVcr\Matching;

use HttpVcr\Cassette\RecordedRequest;

/**
 * Decides whether a recorded request and an incoming one are "the same request".
 *
 * Both sides are immutable snapshots rather than live PSR-7 objects: a matcher can never
 * consume a body out from under the next matcher in the composition, and a body kept
 * outside the cassette file isn't read from disk for matchers that don't look at it.
 */
interface RequestMatcherInterface
{
    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool;
}
