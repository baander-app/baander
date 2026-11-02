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
    public function testHouseholdPermitsOnlyOperationsWithoutAudioVideoEncoding(MediaOperation $operation): void
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

    /** @return iterable<string, array{MediaOperation}> */
    public static function operations(): iterable
    {
        foreach (MediaOperation::cases() as $operation) {
            yield $operation->value => [$operation];
        }
    }
}
