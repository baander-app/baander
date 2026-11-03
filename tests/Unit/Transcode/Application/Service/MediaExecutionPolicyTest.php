<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\Service;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Transcode\Application\DTO\MediaOperation;
use App\Transcode\Application\Exception\MediaEncodingProhibitedException;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaExecutionPolicyTest extends TestCase
{
    #[DataProvider('operations')]
    public function testFullProfilePermitsAllOperations(MediaOperation $operation): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile('full'));
        $policy->assertAllowed($operation);
        self::assertTrue($policy->permits($operation));
    }

    #[DataProvider('operations')]
    public function testHouseholdSharedCopyPoolPermitsBothCopiesButNoEncoding(MediaOperation $operation): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile('household'));
        if ($operation === MediaOperation::AudioEncode || $operation === MediaOperation::VideoEncode) {
            self::assertFalse($policy->permits($operation));
            $this->expectException(MediaEncodingProhibitedException::class);
            $this->expectExceptionMessage('Audio and video encoding is unavailable in the household deployment profile.');
        } else {
            self::assertTrue($policy->permits($operation));
        }
        $policy->assertAllowed($operation);
    }

    public function testFullProfileWithZeroAudioSlotsRefusesAudioEncodesAndCopiesOnly(): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile('full', 2, 0));

        self::assertFalse($policy->permits(MediaOperation::AudioEncode));
        self::assertFalse($policy->permits(MediaOperation::AudioStreamCopy));
        self::assertTrue($policy->permits(MediaOperation::VideoEncode));
        self::assertTrue($policy->permits(MediaOperation::VideoStreamCopy));
        $this->expectException(MediaEncodingProhibitedException::class);
        $this->expectExceptionMessage('Audio encoding and stream copy are unavailable because the deployment runs no audio slots.');
        $policy->assertAllowed(MediaOperation::AudioStreamCopy);
    }

    public function testFullProfileWithZeroVideoSlotsRefusesVideoEncodesAndCopiesOnly(): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile('full', 0, 4));

        self::assertFalse($policy->permits(MediaOperation::VideoEncode));
        self::assertFalse($policy->permits(MediaOperation::VideoStreamCopy));
        self::assertTrue($policy->permits(MediaOperation::AudioEncode));
        self::assertTrue($policy->permits(MediaOperation::AudioStreamCopy));
        $this->expectException(MediaEncodingProhibitedException::class);
        $this->expectExceptionMessage('Video encoding and stream copy are unavailable because the deployment runs no video slots.');
        $policy->assertAllowed(MediaOperation::VideoStreamCopy);
    }

    /** @return iterable<string, array{MediaOperation}> */
    public static function operations(): iterable
    {
        foreach (MediaOperation::cases() as $operation) {
            yield $operation->value => [$operation];
        }
    }
}
