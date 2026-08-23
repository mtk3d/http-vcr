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
     * @param  array<string, string>  $variables
     * @param  array<string, Provider>  $providers  the APIs this project has named, which is
     *                                              what a `@name` in VCR_ERASE_TAPE resolves
     *                                              against before falling back to a bare host
     */
    public function __construct(private array $variables = [], private array $providers = []) {}

    /**
     * @param  array<string, Provider>  $providers
     */
    public static function fromSystem(array $providers = []): self
    {
        $variables = [];

        $names = [
            ...self::CI_VARIABLES,
            'VCR_ALLOW_RECORDING',
            'VCR_ERASE_TAPE',
            'VCR_ENFORCE_STALE_CHECK',
            'VCR_IGNORE_STALE_CASSETTES',
        ];

        foreach ($names as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

            if (is_string($value) && $value !== '') {
                $variables[$name] = $value;
            }
        }

        return new self($variables, $providers);
    }

    /**
     * Which of these variables have no value — the check the recording branch runs before
     * making a real request (§3.12).
     *
     * Read from the process at the moment of asking rather than from the snapshot above:
     * the snapshot covers the VCR_* rules, which have to be settled when the session opens,
     * while a credential may well be put in place by the test's own setUp().
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function missing(array $names): array
    {
        $missing = [];

        foreach ($names as $name) {
            $value = $this->variables[$name] ?? $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $name;
            }
        }

        return $missing;
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
        return EraseTape::parse($this->variables['VCR_ERASE_TAPE'] ?? null, $this->providers);
    }

    /**
     * Whether crossing `staleAfter` should fail the test rather than only being reported
     * (§3.7).
     *
     * Off by default, because a check against the clock is non-deterministic between runs:
     * the same commit can pass in a merge-request pipeline and fail an hour later on the
     * main branch purely because the threshold was crossed in between. A team that wants
     * the forced re-record cadence anyway opts in — and can waive it for a single run,
     * which is why the ignore switch outranks the enforce one rather than the other way
     * round.
     */
    public function enforcesStaleCheck(): bool
    {
        if ($this->isTruthy('VCR_IGNORE_STALE_CASSETTES')) {
            return false;
        }

        return $this->isTruthy('VCR_ENFORCE_STALE_CHECK');
    }

    private function explicitRecordingPermission(): ?bool
    {
        $value = $this->variables['VCR_ALLOW_RECORDING'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return ! in_array(strtolower($value), ['0', 'false'], true);
    }

    private function detectedCiVariable(): ?string
    {
        foreach (self::CI_VARIABLES as $name) {
            if ($this->isTruthy($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Set to anything other than the two spellings of "no". `0`/`false` read as unset
     * everywhere in the library, so a variable left in a pipeline config can be turned off
     * without deleting the line.
     */
    private function isTruthy(string $name): bool
    {
        $value = $this->variables[$name] ?? '';

        return $value !== '' && ! in_array(strtolower($value), ['0', 'false'], true);
    }
}
