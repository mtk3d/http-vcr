<?php

declare(strict_types=1);

namespace HttpVcr\Serializer;

use HttpVcr\Exception\CassetteFormatException;
use JsonException;

/**
 * The default format: the cassette schema written as JSON, chosen for a readable diff in a
 * pull request and for needing no dependency to produce.
 *
 * Fields that carry their default value (`locked`, `repeatablePlayback`) are left out on
 * write and read back as that default — a cassette is meant to be read by a human
 * reviewing a change to it, and forty lines of `"locked": false` work against that.
 */
final class JsonCassetteSerializer extends ArrayCassetteSerializer
{
    public function fileExtension(): string
    {
        return 'json';
    }

    protected function encode(array $data): string
    {
        try {
            $json = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw CassetteFormatException::malformed('could not be encoded as JSON: ' . $exception->getMessage());
        }

        return $json . "\n";
    }

    protected function decode(string $content): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw CassetteFormatException::malformed('is not valid JSON (' . $exception->getMessage() . ')');
        }

        if (!is_array($data) || array_is_list($data)) {
            throw CassetteFormatException::malformed('is not a JSON object');
        }

        return $data;
    }
}
