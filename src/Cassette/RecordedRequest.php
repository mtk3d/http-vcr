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
     * @param  array<string, list<string>>  $headers  header names as sent, values as a list
     * @param  string|null  $bodyEncoding  'base64' when the body is binary, null
     *                                     when it is text; how a cassette has to
     *                                     store it, not what it means
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers = [],
        public string $body = '',
        public ?string $bodyEncoding = null,
    ) {}

    public function withUri(string $uri): self
    {
        return new self($this->method, $uri, $this->headers, $this->body, $this->bodyEncoding);
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->method, $this->uri, $headers, $this->body, $this->bodyEncoding);
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
        return new self($this->method, $this->uri, $this->headers, $body, $encoding);
    }

    /**
     * @return list<string> the values of $name, matched case-insensitively; empty when absent
     */
    public function header(string $name): array
    {
        return Headers::get($this->headers, $name);
    }
}
