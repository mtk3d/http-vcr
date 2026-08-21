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

        // addCommands(), not add()/addCommand(): the package supports symfony/console
        // ^6.4 || ^7.0 || ^8.0, and the two singular methods do not both exist across that
        // range — add() was removed in 8.0, addCommand() only arrived in 7.4.
        $this->addCommands([
            new LockCommand(lock: true),
            new LockCommand(lock: false),
        ]);
    }
}
