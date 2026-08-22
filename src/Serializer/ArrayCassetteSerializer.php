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
use HttpVcr\Persistence\SidecarBodies;
use Throwable;

/**
 * The cassette schema, minus the punctuation it is written in.
 *
 * A cassette is one shape — the same fields, the same defaults left out, the same base64
 * rule for bytes text cannot hold — and JSON or YAML is only how that shape is spelled on
 * disk. Keeping the schema here means a format cannot quietly grow a field of its own, and
 * that a cassette converted from one to the other says exactly the same thing.
 *
 * A subclass supplies three things: the file extension, and the two steps that turn the
 * array into text and back.
 */
abstract class ArrayCassetteSerializer implements CassetteSerializerInterface
{
    /**
     * @param array{schemaVersion: int, interactions: list<array<string, mixed>>} $data
     */
    abstract protected function encode(array $data): string;

    /**
     * @return array<mixed>
     *
     * @throws CassetteFormatException when the text is not this format at all
     */
    abstract protected function decode(string $content): array;

    public function serialize(Cassette $cassette, ?SidecarBodies $bodies = null): string
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
                    ...$this->bodyFields($interaction->request->body, $interaction->request->bodyEncoding, $bodies),
                ],
                'response' => $response === null ? null : [
                    'status' => $response->status,
                    'headers' => $response->headers,
                    ...$this->bodyFields($response->body, $response->bodyEncoding, $bodies),
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

        return $this->encode(['schemaVersion' => $cassette->schemaVersion, 'interactions' => $interactions]);
    }

    public function deserialize(string $content, ?SidecarBodies $bodies = null): Cassette
    {
        $data = $this->decode($content);

        if (array_is_list($data)) {
            throw CassetteFormatException::malformed('is not a cassette: it holds a list, not the cassette fields');
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
            $parsed[] = $this->interaction($interaction, $position + 1, $bodies);
        }

        return new Cassette($parsed, $schemaVersion);
    }

    private function interaction(mixed $data, int $position, ?SidecarBodies $bodies): Interaction
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

        $request = $this->request($data['request'] ?? null, $position, $bodies);
        $outcomeOf = $outcome === Outcome::Error
            ? $this->error($data, $position)
            : $this->response($data['response'] ?? null, $position, $bodies);

        $recordedAt = $this->recordedAt($data['recordedAt'] ?? null, $position);
        $locked = $this->bool($data['locked'] ?? false, 'locked', $position);
        $repeatable = $this->bool($data['repeatablePlayback'] ?? false, 'repeatablePlayback', $position);

        return $outcomeOf instanceof RecordedError
            ? Interaction::failed($request, $outcomeOf, $recordedAt, $locked, $repeatable)
            : Interaction::recorded($request, $outcomeOf, $recordedAt, $locked, $repeatable);
    }

    /**
     * JSON carries text, and a body is not always text. Binary content — anything whose
     * Content-Type isn't textual — is stored base64-encoded and marked as such.
     *
     * Content that claims to be text but isn't valid UTF-8 gets the same treatment: JSON
     * cannot hold those bytes at all, so storing them verbatim isn't an option, and
     * refusing to record would be a strange way to react to a server sending Latin-1.
     *
     * A body past the inline threshold goes to a file of its own instead, leaving a
     * reference and a checksum behind. There is nothing for base64 to solve there — a
     * sidecar holds raw bytes — so the two never appear together.
     *
     * @return array{body?: string, bodyEncoding?: string, bodyRef?: string, bodySha256?: string}
     */
    private function bodyFields(string $body, ?string $encoding, ?SidecarBodies $bodies): array
    {
        $sidecar = $bodies?->offload($body);

        if ($sidecar !== null) {
            return ['bodyRef' => $sidecar['ref'], 'bodySha256' => $sidecar['sha256']];
        }

        if ($encoding === null && preg_match('//u', $body) !== 1) {
            $encoding = 'base64';
        }

        return $encoding === null
            ? ['body' => $body]
            : ['body' => base64_encode($body), 'bodyEncoding' => $encoding];
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{string, string|null}
     */
    private function storedBody(array $data, int $position, ?SidecarBodies $bodies): array
    {
        $ref = $data['bodyRef'] ?? null;

        if (is_string($ref)) {
            if ($bodies === null) {
                throw CassetteFormatException::malformed(sprintf(
                    'keeps the body of interaction #%d in a separate file, which this reader has no access to',
                    $position,
                ));
            }

            $sha256 = $data['bodySha256'] ?? null;

            if (!is_string($sha256)) {
                throw CassetteFormatException::malformed(sprintf(
                    'has a bodyRef without a bodySha256 in interaction #%d',
                    $position,
                ));
            }

            return [$bodies->fetch($ref, $sha256), null];
        }

        $body = $this->body($data['body'] ?? '', $position);
        $encoding = $data['bodyEncoding'] ?? null;

        if ($encoding === null) {
            return [$body, null];
        }

        if ($encoding !== 'base64') {
            throw CassetteFormatException::malformed(sprintf(
                'has an unknown bodyEncoding "%s" in interaction #%d',
                is_string($encoding) ? $encoding : gettype($encoding),
                $position,
            ));
        }

        $decoded = base64_decode($body, true);

        if ($decoded === false) {
            throw CassetteFormatException::malformed(sprintf(
                'has a body that is not valid base64 in interaction #%d',
                $position,
            ));
        }

        return [$decoded, 'base64'];
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

    private function request(mixed $data, int $position, ?SidecarBodies $bodies): RecordedRequest
    {
        if (!is_array($data) || !is_string($data['method'] ?? null) || !is_string($data['uri'] ?? null)) {
            throw CassetteFormatException::malformed(sprintf(
                'has an interaction #%d without a readable request',
                $position,
            ));
        }

        [$body, $encoding] = $this->storedBody($data, $position, $bodies);

        return new RecordedRequest(
            $data['method'],
            $data['uri'],
            $this->headers($data['headers'] ?? [], $position),
            $body,
            $encoding,
        );
    }

    private function response(mixed $data, int $position, ?SidecarBodies $bodies): RecordedResponse
    {
        if (!is_array($data) || !is_int($data['status'] ?? null)) {
            throw CassetteFormatException::malformed(sprintf(
                'has an interaction #%d without a readable response',
                $position,
            ));
        }

        [$body, $encoding] = $this->storedBody($data, $position, $bodies);

        return new RecordedResponse(
            $data['status'],
            $this->headers($data['headers'] ?? [], $position),
            $body,
            $encoding,
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
