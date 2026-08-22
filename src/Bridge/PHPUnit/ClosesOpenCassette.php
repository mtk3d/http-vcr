<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * Makes sure no cassette outlives the test that opened it.
 *
 * Usually there is nothing left to do here: {@see InteractsWithCassettes} closes the
 * session from an `#[After]` method, which runs inside the test, so a strict-mode failure
 * fails that test instead of becoming a runner warning (PHPUnit turns an exception from an
 * event subscriber into one). This is the backstop for a test that declared a cassette
 * without using the trait — the lock still has to go back, and the handle still has to be
 * empty before the next test starts.
 *
 * @internal
 */
final class ClosesOpenCassette implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        CurrentCassetteSession::end();
    }
}
