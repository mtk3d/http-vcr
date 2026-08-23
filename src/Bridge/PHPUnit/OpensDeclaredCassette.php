<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * Opens the cassette a test declared, before the test prepares itself.
 *
 * That moment matters: it is ahead of `setUp()`, so `$this->vcrClient()` is available
 * there and still unfrozen, which is where per-test redaction and hooks are registered
 * (§3.14).
 *
 * @internal
 */
final class OpensDeclaredCassette implements PreparationStartedSubscriber
{
    public function __construct(private readonly CassetteFactory $cassettes) {}

    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if (! $test instanceof TestMethod) {
            return;
        }

        $declared = $this->cassettes->declaredBy($test->className(), $test->methodName());

        if ($declared === null) {
            return;
        }

        CurrentCassetteSession::begin(
            $this->cassettes->open($declared, $this->cassettes->directoryFor($test->className())),
        );
    }
}
