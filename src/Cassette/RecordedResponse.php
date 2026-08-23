<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

/**
 * An immutable snapshot of a response, symmetrical to {@see RecordedRequest}.
 */
final readonly class RecordedResponse
{
    /**
     * @param  array<string, list<string>>  $headers  header names as received, values as a list
     * @param  string|null  $bodyEncoding  'base64' when the body is binary, null
     *                                     when it is text
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
        public ?string $bodyEncoding = null,
    ) {}

    public function withStatus(int $status): self
    {
        return new self($status, $this->headers, $this->body, $this->bodyEncoding);
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $headers, $this->body, $this->bodyEncoding);
    }

    /**
     * @param  string|list<string>  $value
     */
    public function withHeader(string $name, string|array $value): self
    {
        return $this->withHeaders(Headers::with($this->headers, $name, $value));
    }

    public function withoutHeader(string $name): self
    {
        return $this->withHeaders(Headers::without($this->headers, $name));
    }

    public function withBody(string $body, ?string $encoding = null): self
    {
        return new self($this->status, $this->headers, $body, $encoding);
    }

    /**
     * @return list<string> the values of $name, matched case-insensitively; empty when absent
     */
    public function header(string $name): array
    {
        return Headers::get($this->headers, $name);
    }
}
