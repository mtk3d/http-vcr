<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use Attribute;

/**
 * Where this class's cassettes live, for a module that keeps them beside itself rather
 * than in one pile under the project's `tests/Cassettes` (§3.12).
 *
 * Declared once, usually on a module's base test case: the lookup walks up the inheritance
 * chain, since PHP does not inherit attributes on its own, and the first one found wins.
 * `__DIR__` is legal here — attribute arguments are constant expressions — so the path is
 * written where it can be seen and resolves against the file it is written in.
 *
 * Cassette names are untouched by it: `stripe/charge` is still a path inside whichever
 * directory applies, so nothing is routed by name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CassetteDirectory
{
    public function __construct(public string $path) {}
}
