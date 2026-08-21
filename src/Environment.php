<?php

declare(strict_types=1);

namespace HttpVcr;

/**
 * The one place that reads VCR_* variables and decides what they mean (§3.1/§3.12).
 *
 * Kept in a single object rather than getenv() scattered through the core, so the rules —
 * particularly the CI heuristic — are testable without touching the process environment.
 */
final class Environment
{
    /**
     * Deliberately narrow and enumerated, so it can be predicted without reading this
     * file. The first two cover GitHub Actions, GitLab CI, CircleCI, Travis, Buildkite,
     * Drone and most hosted runners; the rest cover Jenkins and TeamCity, which set
     * neither.
     */
    private const CI_VARIABLES = ['CI', 'CONTINUOUS_INTEGRATION', 'BUILD_NUMBER', 'JENKINS_URL', 'TEAMCITY_VERSION'];

    /**
     * @param array<string, string> $variables
     */
    public function __construct(private array $variables = [])
    {
    }

    public static function fromSystem(): self
    {
        $variables = [];

        foreach ([...self::CI_VARIABLES, 'VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE'] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

            if (is_string($value) && $value !== '') {
                $variables[$name] = $value;
            }
        }

        return new self($variables);
    }

    /**
     * Three states, not two: an explicit value always wins, and the default depends on
     * whether this looks like CI.
     */
    public function isRecordingAllowed(): bool
    {
        $explicit = $this->explicitRecordingPermission();

        return $explicit ?? $this->detectedCiVariable() === null;
    }

    /**
     * Why recording is off, named precisely enough to act on — a false positive from CI
     * detection is only bearable if the message says which variable caused it.
     *
     * @return string|null null when recording is allowed
     */
    public function recordingBlockedBecause(): ?string
    {
        if ($this->isRecordingAllowed()) {
            return null;
        }

        if ($this->explicitRecordingPermission() === false) {
            return sprintf('VCR_ALLOW_RECORDING=%s', $this->variables['VCR_ALLOW_RECORDING'] ?? '0');
        }

        $variable = (string) $this->detectedCiVariable();

        return sprintf(
            'CI detection (%s=%s is set, VCR_ALLOW_RECORDING is not)',
            $variable,
            $this->variables[$variable] ?? '',
        );
    }

    public function eraseTape(): EraseTape
    {
        return EraseTape::parse($this->variables['VCR_ERASE_TAPE'] ?? null);
    }

    private function explicitRecordingPermission(): ?bool
    {
        $value = $this->variables['VCR_ALLOW_RECORDING'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return !in_array(strtolower($value), ['0', 'false'], true);
    }

    private function detectedCiVariable(): ?string
    {
        foreach (self::CI_VARIABLES as $name) {
            $value = $this->variables[$name] ?? '';

            if ($value !== '' && !in_array(strtolower($value), ['0', 'false'], true)) {
                return $name;
            }
        }

        return null;
    }
}
