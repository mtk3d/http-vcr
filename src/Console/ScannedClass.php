<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Bridge\PHPUnit\UseCassette;

/**
 * One class as the scan read it, before anything is resolved through its parents.
 *
 * @internal
 */
final readonly class ScannedClass
{
    /**
     * @param  array<string, UseCassette|null>  $methods  the class's own test methods, in the
     *                                                    order they are written, each with the
     *                                                    attribute it carries itself
     */
    public function __construct(
        public string $name,
        public ?string $parent,
        public bool $abstract,
        public string $file,
        public int $line,
        public ?UseCassette $cassette,
        public ?string $directory,
        public array $methods,
    ) {}
}
