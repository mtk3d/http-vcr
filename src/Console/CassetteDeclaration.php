<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use HttpVcr\Bridge\PHPUnit\UseCassette;

/**
 * One test method and the cassette it declared, as read out of the source (§3.12).
 *
 * The declaration itself is the same {@see UseCassette} the extension builds by reflection
 * at run time — the scan resolves the attribute's arguments statically and hands back the
 * object they describe, so both routes to a cassette speak in one vocabulary.
 *
 * A class-level attribute arrives here already spread over the class's test methods, the
 * way it applies when the run happens: one declaration per method, never per class.
 */
final readonly class CassetteDeclaration
{
    /**
     * @param class-string|string $class     the test class, fully qualified
     * @param string              $directory where this class keeps its cassettes when it
     *                                       said so with `#[CassetteDirectory]`; null
     *                                       leaves it to the project configuration
     */
    public function __construct(
        public string $class,
        public string $method,
        public UseCassette $declared,
        public ?string $directory,
        public string $file,
        public int $line,
    ) {
    }

    /**
     * `App\Tests\ShopifyTest::testItReadsAProduct` — how PHPUnit itself names a test, so
     * the string is worth something pasted into `--filter`.
     */
    public function name(): string
    {
        return $this->class . '::' . $this->method;
    }
}
