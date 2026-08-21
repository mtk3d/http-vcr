<?php

declare(strict_types=1);

namespace HttpVcr\Serializer;

use DateTimeImmutable;
use DateTimeInterface;
use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\ErrorCategory;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\Outcome;
use HttpVcr\Cassette\RecordedError;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Exception\CassetteFormatException;
use JsonException;
use Throwable;

/**
 * The default format: a small JSON schema of http-vcr's own, chosen for a readable diff in
 * a pull request and for needing no dependency to produce.
 *
 * Fields that carry their default value (`locked`, `repeatablePlayback`) are left out on
 * write and read back as that default — a cassette is meant to be read by a human
 * reviewing a change to it, and forty lines of `"locked": false` work against that.
 */
final class JsonCassetteSerializer implements CassetteSerializerInterface
{
    public function fileExtension(): string
    {
        return 'json';
    }

    public function serialize(Cassette $cassette): string
    {
        $interactions = [];

        foreach ($cassette->interactions as $interaction) {
            $response = $interaction->response;
            $error = $interaction->error;

            $interactions[] = array_filter([
                'request' => [
                    'method' => $interaction->request->method,
                    'uri' => $interaction->request->uri,
                    'headers' => $interaction->request->headers,
                    'body' => $interaction->request->body,
                ],
                'response' => $response === null ? null : [
                    'status' => $response->status,
                    'headers' => $response->headers,
                    'body' => $response->body,
                ],
                'outcome' => $interaction->outcome->value,
                'errorCategory' => $error?->category->value,
                'errorMessage' => $error?->message,
                'errorClass' => $error?->exceptionClass,
                'recordedAt' => $interaction->recordedAt->format(DateTimeInterface::ATOM),
                'locked' => $interaction->locked,
                'repeatablePlayback' => $interaction->repeatablePlayback,
            ], static fn (mixed $value): bool => $value !== false && $value !== null);
        }

        try {
            $json = json_encode(
                ['schemaVersion' => $cassette->schemaVersion, 'interactions' => $interactions],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw CassetteFormatException::malformed('could not be encoded as JSON: ' . $exception->getMessage());
        }

        return $json . "\n";
    }

    public function deserialize(string $content): Cassette
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw CassetteFormatException::malformed('is not valid JSON (' . $exception->getMessage() . ')');
        }

        if (!is_array($data) || array_is_list($data)) {
            throw CassetteFormatException::malformed('is not a JSON object');
        }

        $schemaVersion = $data['schemaVersion'] ?? null;

        if (!is_int($schemaVersion)) {
            throw CassetteFormatException::malformed('has no schemaVersion');
        }

        if ($schemaVersion !== Cassette::CURRENT_SCHEMA_VERSION) {
            throw CassetteFormatException::unsupportedSchemaVersion($schemaVersion, Cassette::CURRENT_SCHEMA_VERSION);
        }

        $interactions = $data['interactions'] ?? [];

        if (!is_array($interactions) || !array_is_list($interactions)) {
            throw CassetteFormatException::malformed('has no list of interactions');
        }

        $parsed = [];

        foreach ($interactions as $position => $interaction) {
            $parsed[] = $this->interaction($interaction, $position + 1);
        }

        return new Cassette($parsed, $schemaVersion);
    }

    private function interaction(mixed $data, int $position): Interaction
    {
        if (!is_array($data)) {
            throw CassetteFormatException::malformed(sprintf('has a malformed interaction #%d', $position));
        }

        $outcome = Outcome::tryFrom(is_string($data['outcome'] ?? null) ? $data['outcome'] : 'success');

        if ($outcome === null) {
            throw CassetteFormatException::malformed(sprintf(
                'has an unknown outcome "%s" in interaction #%d',
                is_string($data['outcome']) ? $data['outcome'] : gettype($data['outcome']),
                $position,
            ));
        }

        $request = $this->request($data['request'] ?? null, $position);
        $outcomeOf = $outcome === Outcome::Error
            ? $this->error($data, $position)
            : $this->response($data['response'] ?? null, $position);

        $recordedAt = $this->recordedAt($data['recordedAt'] ?? null, $position);
        $locked = $this->bool($data['locked'] ?? false, 'locked', $position);
        $repeatable = $this->bool($data['repeatablePlayback'] ?? false, 'repeatablePlayback', $position);

        return $outcomeOf instanceof RecordedError
            ? Interaction::failed($request, $outcomeOf, $recordedAt, $locked, $repeatable)
            : Interaction::recorded($request, $outcomeOf, $recordedAt, $locked, $repeatable);
    }

    private function error(mixed $data, int $position): RecordedError
    {
        if (!is_array($data)) {
            throw CassetteFormatException::malformed(sprintf('has a malformed interaction #%d', $position));
        }

        $category = ErrorCategory::tryFrom(is_string($data['errorCategory'] ?? null) ? $data['errorCategory'] : '');

        if ($category === null) {
            throw CassetteFormatException::malformed(sprintf(
                'has an interaction #%d recording a failure without a known errorCategory '
                . '("network" or "request")',
                $position,
            ));
        }

        return new RecordedError(
            $category,
            is_string($data['errorMessage'] ?? null) ? $data['errorMessage'] : '',
            is_string($data['errorClass'] ?? null) ? $data['errorClass'] : '',
        );
    }

    private function request(mixed $data, int $position): RecordedRequest
    {
        if (!is_array($data) || !is_string($data['method'] ?? null) || !is_string($data['uri'] ?? null)) {
            throw CassetteFormatException::malformed(sprintf(
                'has an interaction #%d without a readable request',
                $position,
            ));
        }

        return new RecordedRequest(
            $data['method'],
            $data['uri'],
            $this->headers($data['headers'] ?? [], $position),
            $this->body($data['body'] ?? '', $position),
        );
    }

    private function response(mixed $data, int $position): RecordedResponse
    {
        if (!is_array($data) || !is_int($data['status'] ?? null)) {
            throw CassetteFormatException::malformed(sprintf(
                'has an interaction #%d without a readable response',
                $position,
            ));
        }

        return new RecordedResponse(
            $data['status'],
            $this->headers($data['headers'] ?? [], $position),
            $this->body($data['body'] ?? '', $position),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function headers(mixed $data, int $position): array
    {
        if (!is_array($data)) {
            throw CassetteFormatException::malformed(sprintf('has unreadable headers in interaction #%d', $position));
        }

        $headers = [];

        foreach ($data as $name => $values) {
            $values = is_array($values) ? array_values($values) : [$values];

            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw CassetteFormatException::malformed(sprintf(
                        'has a non-string value for header "%s" in interaction #%d',
                        (string) $name,
                        $position,
                    ));
                }
            }

            /** @var list<string> $values */
            $headers[(string) $name] = $values;
        }

        return $headers;
    }

    private function body(mixed $data, int $position): string
    {
        if (!is_string($data)) {
            throw CassetteFormatException::malformed(sprintf('has an unreadable body in interaction #%d', $position));
        }

        return $data;
    }

    private function recordedAt(mixed $data, int $position): DateTimeImmutable
    {
        if (!is_string($data)) {
            throw CassetteFormatException::malformed(sprintf('has no recordedAt in interaction #%d', $position));
        }

        try {
            return new DateTimeImmutable($data);
        } catch (Throwable) {
            throw CassetteFormatException::malformed(sprintf(
                'has an unreadable recordedAt "%s" in interaction #%d',
                $data,
                $position,
            ));
        }
    }

    private function bool(mixed $data, string $field, int $position): bool
    {
        if (!is_bool($data)) {
            throw CassetteFormatException::malformed(sprintf(
                'has a non-boolean "%s" in interaction #%d',
                $field,
                $position,
            ));
        }

        return $data;
    }
}
