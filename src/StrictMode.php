<?php

declare(strict_types=1);

namespace HttpVcr;

/**
 * What the cassette asserts about the way it was replayed, checked when the session closes
 * (§3.6).
 *
 * The mirror image of {@see RecordMode}, which decides what happens when the code asks for
 * something the cassette doesn't have: these two cases catch the cassette holding
 * something the code never asked for, or asking for it out of turn.
 *
 * Both only ever judge the interactions the cassette held when the session opened.
 * Anything the same session recorded is left out — a just-recorded interaction was, by
 * definition, "played", and its position in the file was decided by the very run being
 * measured.
 */
enum StrictMode
{
    /**
     * No assertion: any subset of the cassette, in any order.
     */
    case None;

    /**
     * Every interaction has to be replayed at least once. A repeatable interaction is not
     * consumed, so "at least once" is what unplayed means for it too — one nothing ever
     * asked for still fails, which is exactly the signal this mode exists for.
     */
    case AllPlayed;

    /**
     * Interactions have to be replayed in the order they were recorded. Repeatable
     * interactions sit outside the sequence — typically the target of a retry loop, which
     * would otherwise reorder everything around it.
     */
    case InOrder;
}
