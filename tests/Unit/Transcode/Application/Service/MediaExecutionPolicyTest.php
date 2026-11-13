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
    public function testTranscodeDeploymentPermitsAllOperations(MediaOperation $operation): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile());
        $policy->assertAllowed($operation);
        self::assertTrue($policy->permits($operation));
    }

    #[DataProvider('operations')]
    public function testRemuxDeploymentCopiesBothKindsButRefusesEncoding(MediaOperation $operation): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 8));
        if ($operation === MediaOperation::AudioEncode || $operation === MediaOperation::VideoEncode) {
            self::assertFalse($policy->permits($operation));
            $this->expectException(MediaEncodingProhibitedException::class);
            $this->expectExceptionMessage('Audio and video encoding is unavailable in a remux deployment.');
        } else {
            self::assertTrue($policy->permits($operation));
        }
        $policy->assertAllowed($operation);
    }

    public function testRemuxRefusalCarriesTheEncodingProhibitedReason(): void
    {
        try {
            (new MediaExecutionPolicy(new DeploymentProfile('remux', sharedCopySlots: 1)))->assertAllowed(MediaOperation::VideoEncode);
            self::fail('A remux deployment must refuse video encoding.');
        } catch (MediaEncodingProhibitedException $exception) {
            self::assertSame(['reason' => 'encoding_prohibited'], $exception->details);
        }
    }

    public function testTranscodeDeploymentAtTheSmallestSizeAdmitsVideoEncoding(): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile(webWorkers: 2, doctrinePoolSlots: 4, ordinaryConsumerLimit: 1, videoEncoderSlots: 1, audioEncoderSlots: 2));

        $policy->assertAllowed(MediaOperation::VideoEncode);
        self::assertTrue($policy->permits(MediaOperation::VideoEncode));
        self::assertTrue($policy->permits(MediaOperation::AudioEncode));
    }

    public function testTranscodeDeploymentWithZeroAudioSlotsRefusesAudioEncodesAndCopiesOnly(): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile(videoEncoderSlots: 2, audioEncoderSlots: 0));

        self::assertFalse($policy->permits(MediaOperation::AudioEncode));
        self::assertFalse($policy->permits(MediaOperation::AudioStreamCopy));
        self::assertTrue($policy->permits(MediaOperation::VideoEncode));
        self::assertTrue($policy->permits(MediaOperation::VideoStreamCopy));
        $this->expectException(MediaEncodingProhibitedException::class);
        $this->expectExceptionMessage('Audio encoding and stream copy are unavailable because the deployment runs no audio slots.');
        $policy->assertAllowed(MediaOperation::AudioStreamCopy);
    }

    public function testTranscodeDeploymentWithZeroAudioSlotsNamesTheMissingKindForAudioEncodes(): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile(videoEncoderSlots: 2, audioEncoderSlots: 0));

        $this->expectException(MediaEncodingProhibitedException::class);
        $this->expectExceptionMessage('Audio encoding and stream copy are unavailable because the deployment runs no audio slots.');
        $policy->assertAllowed(MediaOperation::AudioEncode);
    }

    public function testTranscodeDeploymentWithZeroVideoSlotsRefusesVideoEncodesAndCopiesOnly(): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile(videoEncoderSlots: 0, audioEncoderSlots: 4));

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
