<?php

declare(strict_types=1);

namespace HttpVcr;

/**
 * What to do when an incoming request matches nothing in the cassette.
 *
 * Never swapped for another case based on the environment — a test runs with the mode it
 * declares, everywhere. Whether recording is permitted at all is a separate, orthogonal
 * switch (VCR_ALLOW_RECORDING, §3.1), and forced recording is deliberately not a case
 * here: it changes what is left in the cassette to match against, not what to do when
 * nothing does.
 */
enum RecordMode
{
    /**
     * No cassette yet → record one. Cassette already there → replay only, and an unmatched
     * request throws rather than quietly reaching the real API.
     */
    case RecordIfAbsent;

    /**
     * Never records, whatever is missing. Every miss throws.
     */
    case PlaybackOnly;
}
