<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

use DateTimeImmutable;

/**
 * One recorded request and the response it produced, plus the metadata a cassette keeps
 * about it. Hooks receive this type and return a new instance — nothing here mutates.
 */
final readonly class Interaction
{
    public function __construct(
        public RecordedRequest $request,
        public RecordedResponse $response,
        public DateTimeImmutable $recordedAt,
        public bool $locked = false,
        public bool $repeatablePlayback = false,
    ) {
    }

    public function withRequest(RecordedRequest $request): self
    {
        return new self($request, $this->response, $this->recordedAt, $this->locked, $this->repeatablePlayback);
    }

    public function withResponse(RecordedResponse $response): self
    {
        return new self($this->request, $response, $this->recordedAt, $this->locked, $this->repeatablePlayback);
    }

    public function withLocked(bool $locked): self
    {
        return new self($this->request, $this->response, $this->recordedAt, $locked, $this->repeatablePlayback);
    }

    public function withRepeatablePlayback(bool $repeatablePlayback): self
    {
        return new self($this->request, $this->response, $this->recordedAt, $this->locked, $repeatablePlayback);
    }
}
