<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * `vendor/bin/http-vcr` (§3.12).
 *
 * Nothing here is reachable from the record/replay path: symfony/console and
 * nikic/php-parser are regular dependencies of the package so that the commands work
 * straight after `composer require --dev`, and the core never touches either (§1).
 */
final class Application extends ConsoleApplication
{
    public function __construct()
    {
        parent::__construct('http-vcr');

        $this->add(new LockCommand(lock: true));
        $this->add(new LockCommand(lock: false));
    }
}
