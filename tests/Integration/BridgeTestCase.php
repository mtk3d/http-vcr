<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Bridge\PHPUnit\CassetteDirectory;
use HttpVcr\Bridge\PHPUnit\InteractsWithCassettes;
use PHPUnit\Framework\TestCase;

/**
 * The shape the bridge is meant to be used in: one base class per module says where its
 * cassettes live, and the tests under it say nothing about paths at all (§3.12).
 */
#[CassetteDirectory(__DIR__ . '/Cassettes')]
abstract class BridgeTestCase extends TestCase
{
    use InteractsWithCassettes;
}
