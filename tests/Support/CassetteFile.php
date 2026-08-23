<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Support;

use LogicException;

/**
 * Typed access to what actually landed in a cassette file, so assertions read as
 * statements about the recording rather than about array offsets.
 */
final class CassetteFile
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(string $json)
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new LogicException('The cassette is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        $this->data = $decoded;
    }

    public function schemaVersion(): int
    {
        $version = $this->data['schemaVersion'] ?? null;

        return is_int($version) ? $version : -1;
    }

    public function count(): int
    {
        return count($this->interactions());
    }

    public function requestUri(int $index): string
    {
        return $this->string($this->part($index, 'request'), 'uri');
    }

    public function requestBody(int $index): string
    {
        return $this->string($this->part($index, 'request'), 'body');
    }

    public function responseBody(int $index): string
    {
        return $this->string($this->part($index, 'response'), 'body');
    }

    /**
     * @return list<string>
     */
    public function responseBodies(): array
    {
        return array_map(fn (int $index): string => $this->responseBody($index), range(0, $this->count() - 1));
    }

    /**
     * @return list<string>
     */
    public function responseHeader(int $index, string $name): array
    {
        return $this->header($index, 'response', $name);
    }

    /**
     * @return list<string>
     */
    public function requestHeader(int $index, string $name): array
    {
        return $this->header($index, 'request', $name);
    }

    /**
     * @return list<string>
     */
    private function header(int $index, string $part, string $name): array
    {
        $headers = $this->part($index, $part)['headers'] ?? [];

        if (! is_array($headers)) {
            return [];
        }

        foreach ($headers as $header => $values) {
            if (strcasecmp((string) $header, $name) === 0 && is_array($values)) {
                return array_values(array_map(
                    static fn (mixed $value): string => is_string($value) ? $value : '',
                    $values,
                ));
            }
        }

        return [];
    }

    public function bodyRef(int $index): string
    {
        return $this->string($this->part($index, 'response'), 'bodyRef');
    }

    public function bodySha256(int $index): string
    {
        return $this->string($this->part($index, 'response'), 'bodySha256');
    }

    public function bodyEncoding(int $index): string
    {
        return $this->string($this->part($index, 'response'), 'bodyEncoding');
    }

    public function requestBodyEncoding(int $index): string
    {
        return $this->string($this->part($index, 'request'), 'bodyEncoding');
    }

    /**
     * The body exactly as the file holds it — base64 and all.
     */
    public function rawResponseBody(int $index): string
    {
        return $this->string($this->part($index, 'response'), 'body');
    }

    public function rawRequestBody(int $index): string
    {
        return $this->string($this->part($index, 'request'), 'body');
    }

    public function outcome(int $index): string
    {
        $outcome = $this->interactions()[$index]['outcome'] ?? null;

        return is_string($outcome) ? $outcome : '';
    }

    public function errorCategory(int $index): string
    {
        return $this->field($index, 'errorCategory');
    }

    public function errorMessage(int $index): string
    {
        return $this->field($index, 'errorMessage');
    }

    public function errorClass(int $index): string
    {
        return $this->field($index, 'errorClass');
    }

    public function hasResponse(int $index): bool
    {
        return isset($this->interactions()[$index]['response']);
    }

    private function field(int $index, string $name): string
    {
        $value = $this->interactions()[$index][$name] ?? null;

        return is_string($value) ? $value : '';
    }

    public function isLocked(int $index): bool
    {
        return ($this->interactions()[$index]['locked'] ?? false) === true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function interactions(): array
    {
        $interactions = $this->data['interactions'] ?? [];

        if (! is_array($interactions)) {
            throw new LogicException('The cassette has no interactions.');
        }

        $typed = [];

        foreach ($interactions as $interaction) {
            if (! is_array($interaction)) {
                throw new LogicException('The cassette has a malformed interaction.');
            }

            /** @var array<string, mixed> $interaction */
            $typed[] = $interaction;
        }

        return $typed;
    }

    /**
     * @return array<string, mixed>
     */
    private function part(int $index, string $name): array
    {
        $part = $this->interactions()[$index][$name] ?? null;

        if (! is_array($part)) {
            throw new LogicException(sprintf('Interaction #%d has no %s.', $index + 1, $name));
        }

        /** @var array<string, mixed> $part */
        return $part;
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function string(array $part, string $field): string
    {
        $value = $part[$field] ?? null;

        return is_string($value) ? $value : '';
    }
}
