<?php

declare(strict_types=1);

namespace HttpVcr\Persistence;

use HttpVcr\Exception\CassetteIntegrityException;

/**
 * Bodies too large to sit inside the cassette file, kept in files of their own beside it
 * (§3.2).
 *
 * Base64 costs about a third more than the bytes it encodes, and json_encode holds another
 * copy of the whole thing — for a few hundred megabytes of downloaded file that is a
 * guaranteed out-of-memory error, whatever the read into a buffer managed. Above the
 * threshold the body goes to `{cassette}.{sha256(body)[0:16]}.bin` and the interaction
 * keeps a reference instead.
 *
 * The name comes from the content, not from the interaction's position: this format invites
 * hand-editing, and a reference computed from a position would point at someone else's
 * sidecar the moment two interactions were swapped. Content naming also deduplicates
 * identical bodies for free.
 */
final class SidecarBodies
{
    private const EXTENSION = 'bin';

    /** @var array<string, true> */
    private array $seen = [];

    /**
     * @param  string  $cassetteName  the cassette file actually open, scope suffix included and
     *                                format extension excluded — two scopes of one cassette must
     *                                not share a sidecar namespace
     */
    public function __construct(
        private readonly CassettePersisterInterface $persister,
        private readonly string $cassetteName,
        private readonly int $inlineBodyLimit,
    ) {}

    /**
     * @return array{ref: string, sha256: string}|null null when the body belongs inline
     */
    public function offload(string $body): ?array
    {
        if (strlen($body) <= $this->inlineBodyLimit) {
            return null;
        }

        $sha256 = hash('sha256', $body);
        $ref = substr($sha256, 0, 16);

        $this->persister->write($this->key($ref), $body);
        $this->seen[$ref] = true;

        return ['ref' => $ref, 'sha256' => $sha256];
    }

    /**
     * @throws CassetteIntegrityException when the file is gone or no longer hashes to what
     *                                    the interaction recorded for it
     */
    public function fetch(string $ref, string $sha256): string
    {
        $key = $this->key($ref);
        $body = $this->persister->read($key);

        if ($body === null) {
            throw CassetteIntegrityException::missingBodyFile($this->persister->describe($key));
        }

        if (hash('sha256', $body) !== $sha256) {
            throw CassetteIntegrityException::alteredBodyFile($this->persister->describe($key));
        }

        $this->seen[$ref] = true;

        return $body;
    }

    /**
     * Deletes this cassette's sidecars that nothing in it references any more — after a
     * forced re-record, after an interaction is deleted by hand, after a body shrinks below
     * the threshold. Safe with deduplication: two interactions sharing a body share one
     * reference, so the file only goes when the last of them does.
     */
    public function collectGarbage(): void
    {
        $prefix = $this->cassetteName.'.';

        foreach ($this->persister->list(self::EXTENSION, $prefix) as $name) {
            if (! isset($this->seen[substr($name, strlen($prefix))])) {
                $this->persister->delete($name.'.'.self::EXTENSION);
            }
        }
    }

    private function key(string $ref): string
    {
        return sprintf('%s.%s.%s', $this->cassetteName, $ref, self::EXTENSION);
    }
}
