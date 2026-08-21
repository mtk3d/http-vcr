<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

/**
 * An immutable snapshot of a request: what matchers compare and what a cassette stores.
 *
 * Never a live PSR-7 object — a PSR-7 body is a mutable stream, so one matcher reading it
 * would leave the next one with nothing to read.
 */
final readonly class RecordedRequest
{
    /**
     * @param array<string, list<string>> $headers header names as sent, values as a list
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    public function withUri(string $uri): self
    {
        return new self($this->method, $uri, $this->headers, $this->body);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->method, $this->uri, $headers, $this->body);
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
        return new self($this->method, $this->uri, $this->headers, $body);
    }

    /**
     * @return list<string> the values of $name, matched case-insensitively; empty when absent
     */
    public function header(string $name): array
    {
        return Headers::get($this->headers, $name);
    }
}
