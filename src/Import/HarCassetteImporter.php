<?php

declare(strict_types=1);

namespace HttpVcr\Import;

use DateTimeImmutable;
use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\ErrorCategory;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedError;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Exception\CassetteFormatException;
use JsonException;
use Throwable;

/**
 * A HAR file turned into a cassette (§3.2).
 *
 * Import and export, deliberately not a {@see \HttpVcr\Serializer\CassetteSerializerInterface}:
 * HAR is somebody else's archive format and has nowhere to put `schemaVersion`, a recorded
 * transport failure, `bodyRef` or `repeatablePlayback`, so storing cassettes in it would
 * mean either leaving the specification or dropping what those fields do.
 *
 * What it is for is a starting point from outside — the Network tab in a browser, Postman,
 * a proxy — converted once into a cassette that then lives in the project's own format:
 *
 * ```php
 * $cassette = (new HarCassetteImporter())->import(file_get_contents('network.har'));
 * (new FilesystemCassettePersister($dir))->write(
 *     'shopify/checkout.json',
 *     (new JsonCassetteSerializer())->serialize($cassette),
 * );
 * ```
 *
 * An entry a browser recorded as never having completed (`status: 0`) becomes a recorded
 * network failure rather than being dropped — that is a thing http-vcr can replay (§3.1),
 * and silently losing entries would make the count in the file disagree with the count in
 * the cassette.
 */
final class HarCassetteImporter
{
    public function import(string $har): Cassette
    {
        try {
            $data = json_decode($har, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw CassetteFormatException::malformed('is not valid JSON (' . $exception->getMessage() . ')');
        }

        if (!is_array($data) || !is_array($data['log'] ?? null) || !is_array($data['log']['entries'] ?? null)) {
            throw CassetteFormatException::malformed('is not a HAR file: there is no log.entries in it');
        }

        $interactions = [];

        foreach ($data['log']['entries'] as $position => $entry) {
            if (!is_array($entry)) {
                throw CassetteFormatException::malformed(sprintf('has a malformed entry #%d', (int) $position + 1));
            }

            $interactions[] = $this->interaction($entry, (int) $position + 1);
        }

        return new Cassette($interactions);
    }

    /**
     * @param array<mixed> $entry
     */
    private function interaction(array $entry, int $position): Interaction
    {
        $request = $this->request($entry['request'] ?? null, $position);
        $response = is_array($entry['response'] ?? null) ? $entry['response'] : [];
        $recordedAt = $this->recordedAt($entry['startedDateTime'] ?? null, $position);
        $status = $response['status'] ?? null;

        if (!is_int($status)) {
            throw CassetteFormatException::malformed(sprintf('has an entry #%d without a response status', $position));
        }

        if ($status === 0) {
            return Interaction::failed(
                $request,
                new RecordedError(
                    ErrorCategory::Network,
                    is_string($entry['_error'] ?? null) ? $entry['_error'] : 'The request never completed',
                    '',
                ),
                $recordedAt,
            );
        }

        [$body, $encoding] = $this->content(is_array($response['content'] ?? null) ? $response['content'] : []);

        return Interaction::recorded(
            $request,
            new RecordedResponse($status, $this->headers($response['headers'] ?? []), $body, $encoding),
            $recordedAt,
        );
    }

    private function request(mixed $data, int $position): RecordedRequest
    {
        if (!is_array($data) || !is_string($data['method'] ?? null) || !is_string($data['url'] ?? null)) {
            throw CassetteFormatException::malformed(sprintf('has an entry #%d without a readable request', $position));
        }

        $postData = is_array($data['postData'] ?? null) ? $data['postData'] : [];

        return new RecordedRequest(
            $data['method'],
            $data['url'],
            $this->headers($data['headers'] ?? []),
            is_string($postData['text'] ?? null) ? $postData['text'] : '',
        );
    }

    /**
     * HAR keeps a body it could not write as text base64-encoded, in the same field, marked
     * by `content.encoding` — so the two formats agree about what happened to the bytes and
     * only spell it differently.
     *
     * @param array<mixed> $content
     *
     * @return array{string, string|null}
     */
    private function content(array $content): array
    {
        $text = $content['text'] ?? '';

        if (!is_string($text) || $text === '') {
            return ['', null];
        }

        if (($content['encoding'] ?? null) !== 'base64') {
            return [$text, null];
        }

        $decoded = base64_decode($text, true);

        if ($decoded === false) {
            throw CassetteFormatException::malformed('has a response body that is not valid base64');
        }

        return [$decoded, 'base64'];
    }

    /**
     * HAR writes headers as a list of name/value pairs, repeated names included — which is
     * the same information as a name with several values, differently arranged.
     *
     * @return array<string, list<string>>
     */
    private function headers(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $headers = [];

        foreach ($data as $header) {
            if (!is_array($header) || !is_string($header['name'] ?? null) || !is_string($header['value'] ?? null)) {
                continue;
            }

            $headers[$header['name']][] = $header['value'];
        }

        return $headers;
    }

    private function recordedAt(mixed $value, int $position): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw CassetteFormatException::malformed(sprintf(
                'has an entry #%d without a startedDateTime',
                $position,
            ));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw CassetteFormatException::malformed(sprintf(
                'has an unreadable startedDateTime "%s" in entry #%d',
                $value,
                $position,
            ));
        }
    }
}
