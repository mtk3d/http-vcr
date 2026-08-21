<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use HttpVcr\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    public function testRecordingIsAllowedWhenNothingSuggestsCi(): void
    {
        $environment = new Environment([]);

        self::assertTrue($environment->isRecordingAllowed());
        self::assertNull($environment->recordingBlockedBecause());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ciVariables(): iterable
    {
        yield 'CI' => ['CI'];
        yield 'CONTINUOUS_INTEGRATION' => ['CONTINUOUS_INTEGRATION'];
        yield 'BUILD_NUMBER' => ['BUILD_NUMBER'];
        yield 'JENKINS_URL' => ['JENKINS_URL'];
        yield 'TEAMCITY_VERSION' => ['TEAMCITY_VERSION'];
    }

    #[DataProvider('ciVariables')]
    public function testAnyOfTheFiveCiVariablesBlocksRecording(string $variable): void
    {
        $environment = new Environment([$variable => 'true']);

        self::assertFalse($environment->isRecordingAllowed());
        self::assertSame(
            sprintf('CI detection (%s=true is set, VCR_ALLOW_RECORDING is not)', $variable),
            $environment->recordingBlockedBecause(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonSignals(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'false' => ['false'];
    }

    #[DataProvider('nonSignals')]
    public function testACiVariableSetToNothingMeaningfulIsNotACiSignal(string $value): void
    {
        self::assertTrue((new Environment(['CI' => $value]))->isRecordingAllowed());
    }

    public function testAnExplicitPermissionBeatsCiDetectionBothWays(): void
    {
        self::assertTrue((new Environment(['CI' => 'true', 'VCR_ALLOW_RECORDING' => '1']))->isRecordingAllowed());
        self::assertFalse((new Environment(['VCR_ALLOW_RECORDING' => '0']))->isRecordingAllowed());
    }

    public function testAnExplicitZeroIsReportedAsExplicitRatherThanBlamedOnCi(): void
    {
        $environment = new Environment(['CI' => 'true', 'VCR_ALLOW_RECORDING' => '0']);

        self::assertSame('VCR_ALLOW_RECORDING=0', $environment->recordingBlockedBecause());
    }
}
