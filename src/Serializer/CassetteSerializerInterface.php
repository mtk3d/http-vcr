<?php

declare(strict_types=1);

namespace HttpVcr\Serializer;

use HttpVcr\Cassette\Cassette;
use HttpVcr\Exception\CassetteFormatException;

/**
 * The on-disk format of a cassette.
 *
 * The unit of exchange is a {@see Cassette}, not a bare list of interactions: the schema
 * version is a property of the file rather than of any interaction in it, and a serializer
 * that couldn't carry it would have nowhere to put the one thing that makes migrating an
 * old cassette possible.
 */
interface CassetteSerializerInterface
{
    public function serialize(Cassette $cassette): string;

    /**
     * @throws CassetteFormatException on an unreadable cassette or an unsupported schema
     */
    public function deserialize(string $content): Cassette;

    /**
     * The file extension for this format, without the dot — `json`, `yaml`. The persister
     * stores bytes under a key and doesn't know the format, so the key is built from this.
     */
    public function fileExtension(): string;
}
