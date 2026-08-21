<?php

declare(strict_types=1);

namespace HttpVcr\Cassette;

use DateTimeImmutable;

/**
 * One recorded request and what came of it — a response, or a transport failure — plus the
 * metadata a cassette keeps about it. Hooks receive this type and return a new instance;
 * nothing here mutates.
 *
 * Built through {@see recorded()} and {@see failed()} rather than a public constructor, so
 * "a response and an error at once" and "neither" are states that cannot be expressed:
 * `outcome` follows from which of the two an instance holds.
 */
final readonly class Interaction
{
    public Outcome $outcome;

    private function __construct(
        public RecordedRequest $request,
        public ?RecordedResponse $response,
        public ?RecordedError $error,
        public DateTimeImmutable $recordedAt,
        public bool $locked = false,
        public bool $repeatablePlayback = false,
    ) {
        $this->outcome = $response !== null ? Outcome::Success : Outcome::Error;
    }

    public static function recorded(
        RecordedRequest $request,
        RecordedResponse $response,
        DateTimeImmutable $recordedAt,
        bool $locked = false,
        bool $repeatablePlayback = false,
    ): self {
        return new self($request, $response, null, $recordedAt, $locked, $repeatablePlayback);
    }

    public static function failed(
        RecordedRequest $request,
        RecordedError $error,
        DateTimeImmutable $recordedAt,
        bool $locked = false,
        bool $repeatablePlayback = false,
    ): self {
        return new self($request, null, $error, $recordedAt, $locked, $repeatablePlayback);
    }

    public function withRequest(RecordedRequest $request): self
    {
        return new self($request, $this->response, $this->error, $this->recordedAt, $this->locked, $this->repeatablePlayback);
    }

    /**
     * Also turns a recorded failure back into a successful interaction — there is no state
     * in which both halves are set.
     */
    public function withResponse(RecordedResponse $response): self
    {
        return new self($this->request, $response, null, $this->recordedAt, $this->locked, $this->repeatablePlayback);
    }

    public function withError(RecordedError $error): self
    {
        return new self($this->request, null, $error, $this->recordedAt, $this->locked, $this->repeatablePlayback);
    }

    public function withLocked(bool $locked): self
    {
        return new self($this->request, $this->response, $this->error, $this->recordedAt, $locked, $this->repeatablePlayback);
    }

    public function withRepeatablePlayback(bool $repeatablePlayback): self
    {
        return new self($this->request, $this->response, $this->error, $this->recordedAt, $this->locked, $repeatablePlayback);
    }
}
