<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

/**
 * An immutable snapshot of a response, symmetrical to {@see RecordedRequest}.
 */
final readonly class RecordedResponse
{
    /**
     * @param array<string, list<string>> $headers header names as received, values as a list
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    public function withStatus(int $status): self
    {
        return new self($status, $this->headers, $this->body);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $headers, $this->body);
    }

    /**
     * @param string|list<string> $value
     */
    public function withHeader(string $name, string|array $value): self
    {
        return $this->withHeaders(Headers::with($this->headers, $name, $value));
    }

    public function withoutHeader(string $name): self
    {
        return $this->withHeaders(Headers::without($this->headers, $name));
    }

    public function withBody(string $body): self
    {
        return new self($this->status, $this->headers, $body);
    }

    /**
     * @return list<string> the values of $name, matched case-insensitively; empty when absent
     */
    public function header(string $name): array
    {
        return Headers::get($this->headers, $name);
    }
}
