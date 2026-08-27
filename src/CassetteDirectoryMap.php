<?php

declare(strict_types=1);

namespace HttpVcr;

use InvalidArgumentException;

/**
 * Which cassette directory a test file belongs to, decided by where the file is (§3.12).
 *
 * `#[CassetteDirectory]` answers the same question one class at a time, which is the right
 * shape for one module and the wrong one for twenty: the answer is identical for every
 * module, and repeating it on twenty base classes means the twenty-first is the one that
 * quietly writes into the project-wide directory instead. Stated once as a rule, a module
 * added later is covered by having been put where the others are.
 *
 * ```php
 * cassetteDirectories: ['tests/Modules/*&#47;' => '{match}/Cassettes']
 * ```
 *
 * A pattern is matched against the test file's path relative to the project root, from the
 * start and up to a directory boundary: `tests/Modules/*` covers every file under
 * `tests/Modules/Shopify`, however deep. `*` stays inside one segment, `**` crosses them.
 * In the directory, `{match}` is the part of the path the pattern matched and `{1}`, `{2}`
 * … are what each star matched on its own. A relative directory resolves against the
 * project root; an absolute one is left where it points.
 *
 * The first pattern that matches wins, so a special case goes above the general rule.
 */
final readonly class CassetteDirectoryMap
{
    /**
     * @param  array<string, string>  $patterns  path pattern to cassette directory
     */
    public function __construct(private array $patterns = [], private string $root = '')
    {
        foreach ($this->patterns as $pattern => $directory) {
            $this->refuseUnmatchedPlaceholders($pattern, $directory);
        }
    }

    /**
     * @param  string  $file  the absolute path of the file declaring the test
     */
    public function directoryFor(string $file): ?string
    {
        $relative = $this->relative($file);

        if ($relative === null) {
            return null;
        }

        foreach ($this->patterns as $pattern => $directory) {
            $captures = $this->match($pattern, $relative);

            if ($captures !== null) {
                return $this->resolve(strtr($directory, $captures));
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    /**
     * @return array<string, string>|null the substitutions this pattern makes available,
     *                                    or null when it does not match
     */
    private function match(string $pattern, string $path): ?array
    {
        if (preg_match($this->expression($pattern), $path, $found) !== 1) {
            return null;
        }

        $captures = ['{match}' => $found[0]];

        for ($star = 1; $star < count($found); $star++) {
            $captures['{'.$star.'}'] = $found[$star];
        }

        return $captures;
    }

    /**
     * Anchored at the start and stopping at a directory boundary, so a pattern names a
     * directory rather than a prefix of a name: `tests/Modules/*` is `tests/Modules/Shopify`
     * and never `tests/ModulesLegacy`.
     */
    private function expression(string $pattern): string
    {
        $quoted = preg_quote(rtrim(str_replace('\\', '/', $pattern), '/'), '#');

        return '#^'.str_replace(['\*\*', '\*'], ['(.*)', '([^/]*)'], $quoted).'(?=/|$)#';
    }

    /**
     * The path as the patterns are written — relative to the project root. A file outside
     * the root has nothing the patterns could match, which is not an error: a test can
     * legitimately live in a package outside the project being tested.
     */
    private function relative(string $file): ?string
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $this->root), '/').'/';

        if (str_starts_with($file, $root)) {
            return substr($file, strlen($root));
        }

        // A path that isn't absolute was written relative to the root already — which is
        // how a project naming its own `testDirectories` hands them over.
        return str_starts_with($file, '/') ? null : $file;
    }

    private function resolve(string $directory): string
    {
        return str_starts_with($directory, '/') ? $directory : rtrim($this->root, '/').'/'.$directory;
    }

    /**
     * A `{2}` where the pattern has one star is a typo that would otherwise write cassettes
     * to a directory with a literal `{2}` in its name — found when the map is built rather
     * than by whoever goes looking for the recordings later.
     */
    private function refuseUnmatchedPlaceholders(string $pattern, string $directory): void
    {
        $stars = preg_match_all('#\*\*?#', str_replace('\\', '/', $pattern));

        if (preg_match_all('#\{(\d+)\}#', $directory, $used) === false) {
            return;
        }

        foreach ($used[1] as $index) {
            if ((int) $index < 1 || (int) $index > $stars) {
                throw new InvalidArgumentException(sprintf(
                    'Cassette directory "%s" refers to "{%s}", but the pattern "%s" has %d wildcard%s.',
                    $directory,
                    $index,
                    $pattern,
                    $stars,
                    $stars === 1 ? '' : 's',
                ));
            }
        }
    }
}
