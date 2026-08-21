<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Support;

/**
 * Puts the VCR_* variables under the test's control and gives them back afterwards — the
 * library's behaviour under CI detection is decided per test, not by whatever machine
 * happens to be running the suite.
 */
trait ControlsEnvironment
{
    /** @var array<string, string|null> */
    private array $savedEnvironment = [];

    private function takeOverEnvironment(string ...$names): void
    {
        foreach ($names as $name) {
            $value = $_ENV[$name] ?? null;
            $this->savedEnvironment[$name] = is_string($value) ? $value : null;
            unset($_ENV[$name]);
        }
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->savedEnvironment as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        $this->savedEnvironment = [];
    }
}
