<?php

declare(strict_types=1);

namespace HttpVcr\Hook;

use HttpVcr\Cassette\Interaction;
use LogicException;

/**
 * The callables that see an interaction on its way to disk and on its way back out,
 * in registration order (§3.5).
 *
 * Lives with the rest of the cassette session's state rather than on VcrClient: the
 * Guzzle bridge produces a new VcrClient per request out of withInner(), and hooks
 * registered on one of them have to apply to all of them.
 *
 * @internal
 */
final class HookRegistry
{
    /** @var list<callable(Interaction): ?Interaction> */
    private array $beforeRecord = [];

    /** @var list<callable(Interaction): Interaction> */
    private array $beforePlayback = [];

    /**
     * @param callable(Interaction): ?Interaction $hook
     */
    public function addBeforeRecord(callable $hook): void
    {
        $this->beforeRecord[] = $hook;
    }

    /**
     * @param callable(Interaction): Interaction $hook
     */
    public function addBeforePlayback(callable $hook): void
    {
        $this->beforePlayback[] = $hook;
    }

    /**
     * @return Interaction|null null when a hook rejected the interaction, meaning "don't
     *                          record this one" — not an error: the request was really
     *                          made and its response goes back to the code under test as
     *                          usual, only the cassette write is skipped
     */
    public function beforeRecord(Interaction $interaction): ?Interaction
    {
        foreach ($this->beforeRecord as $hook) {
            $result = $hook($interaction);

            // The first refusal ends the chain: the hooks after it would have nothing
            // left to receive.
            if ($result === null) {
                return null;
            }

            $interaction = $result;
        }

        return $interaction;
    }

    /**
     * Unlike the record direction, null is not an answer here: the interaction exists and
     * has been matched, and "don't replay it" says nothing about what sendRequest() should
     * return.
     */
    public function beforePlayback(Interaction $interaction): Interaction
    {
        foreach ($this->beforePlayback as $hook) {
            $result = $hook($interaction);

            if (!$result instanceof Interaction) {
                throw new LogicException(
                    'A beforePlayback hook returned null. The interaction has already been matched, so '
                    . 'there is no sensible response to hand back in its place — return the interaction, '
                    . 'changed or unchanged. Only beforeRecord may refuse one.',
                );
            }

            $interaction = $result;
        }

        return $interaction;
    }
}
