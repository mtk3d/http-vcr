<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use Attribute;
use DateInterval;
use HttpVcr\RecordMode;
use HttpVcr\StrictMode;

/**
 * The cassette a test replays from, and how (§3.12).
 *
 * Every field is a {@see \HttpVcr\VcrClient} constructor parameter under the same name —
 * the attribute adds nothing of its own, it only says which of them this test wants.
 *
 * On a class it is sugar for putting the identical attribute on every test method in it,
 * not a session those methods share: each one still opens and closes the same file
 * independently, with its own replay bookkeeping. A method-level attribute replaces a
 * class-level one outright rather than merging with it, the way PHPUnit's own attributes
 * behave.
 *
 * Reading it needs the extension registered in phpunit.xml. Without that entry nothing
 * looks at it and the test makes real requests — which is why {@see
 * InteractsWithCassettes::vcrClient()} refuses rather than handing back an unconfigured
 * client.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class UseCassette
{
    /**
     * @param string             $name        a path inside the cassette directory, without an
     *                                        extension: `shopify/get-product`
     * @param StrictMode|null    $strictMode  null leaves it to the project configuration, which
     *                                        asserts nothing about replay by default (§3.6)
     * @param DateInterval|null  $staleAfter  how long this recording stays fresh; null tracks
     *                                        nothing (§3.7)
     * @param list<string>       $requiresEnv variables this cassette needs before it may record,
     *                                        on top of whatever its provider declared (§3.12)
     * @param bool               $locked      locks the whole file for the length of the run, above
     *                                        anything the data says and above every environment
     *                                        variable (§3.1)
     */
    public function __construct(
        public string $name,
        public RecordMode $mode = RecordMode::RecordIfAbsent,
        public ?StrictMode $strictMode = null,
        public ?DateInterval $staleAfter = null,
        public array $requiresEnv = [],
        public bool $locked = false,
    ) {
    }
}
