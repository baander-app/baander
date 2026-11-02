<?php

declare(strict_types=1);

namespace App\Transcode\Application\Service;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Transcode\Application\DTO\MediaOperation;
use App\Transcode\Application\Exception\MediaEncodingProhibitedException;
use App\Transcode\Application\Port\MediaExecutionPolicyInterface;

final readonly class MediaExecutionPolicy implements MediaExecutionPolicyInterface
{
    public function __construct(private DeploymentProfile $profile)
    {
    }

    public function permits(MediaOperation $operation): bool
    {
        return match ($operation) {
            MediaOperation::AudioEncode => $this->profile->audioEncoderSlots > 0,
            MediaOperation::VideoEncode => $this->profile->videoEncoderSlots > 0,
            MediaOperation::StreamCopy, MediaOperation::Probe, MediaOperation::AudioAnalysis,
            MediaOperation::ImageDerivative, MediaOperation::SubtitleExtraction => true,
        };
    }

    public function assertAllowed(MediaOperation $operation): void
    {
        if (!$this->permits($operation)) {
            throw new MediaEncodingProhibitedException();
        }
    }
}
