<?php

declare(strict_types=1);

namespace HttpVcr\Console;

/**
 * What one pass over the project's test files found (§3.12).
 *
 * The unanalyzed notes travel with the declarations rather than being thrown away,
 * because a command that silently listed nine of ten cassettes would read as "the tenth
 * has no threshold" — which is a different statement from "the tenth could not be read".
 */
final readonly class ScannedTests
{
    /**
     * @param  list<CassetteDeclaration>  $declarations
     * @param  list<string>  $unanalyzed  one line per attribute argument the
     *                                    scan could not resolve statically
     */
    public function __construct(
        public array $declarations,
        public array $unanalyzed,
    ) {}

    /**
     * The cassette directories the scanned classes declared with `#[CassetteDirectory]`,
     * each once — what a command sweeping the whole project has to visit on top of the
     * configured one.
     *
     * @return list<string>
     */
    public function directories(): array
    {
        $directories = [];

        foreach ($this->declarations as $declaration) {
            if ($declaration->directory !== null && ! in_array($declaration->directory, $directories, true)) {
                $directories[] = $declaration->directory;
            }
        }

        return $directories;
    }

    /**
     * @return array<string, list<CassetteDeclaration>> keyed by cassette name, in name order
     */
    public function byCassette(): array
    {
        $grouped = [];

        foreach ($this->declarations as $declaration) {
            $grouped[$declaration->declared->name][] = $declaration;
        }

        ksort($grouped);

        return $grouped;
    }
}
