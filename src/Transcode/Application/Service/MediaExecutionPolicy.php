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
        $remux = $this->profile->capability === 'remux';

        return match ($operation) {
            // Encoding runs only under the transcode capability, in the kind's slots.
            MediaOperation::AudioEncode => !$remux && $this->profile->audioEncoderSlots > 0,
            MediaOperation::VideoEncode => !$remux && $this->profile->videoEncoderSlots > 0,
            // A remux deployment copies both kinds in its shared copy pool; a transcode deployment copies in the kind's slots.
            MediaOperation::VideoStreamCopy => $remux ? $this->profile->sharedCopySlots > 0 : $this->profile->videoEncoderSlots > 0,
            MediaOperation::AudioStreamCopy => $remux ? $this->profile->sharedCopySlots > 0 : $this->profile->audioEncoderSlots > 0,
            MediaOperation::Probe, MediaOperation::AudioAnalysis,
            MediaOperation::ImageDerivative, MediaOperation::SubtitleExtraction => true,
        };
    }

    public function assertAllowed(MediaOperation $operation): void
    {
        if (!$this->permits($operation)) {
            throw new MediaEncodingProhibitedException($this->profile->capability === 'remux' ? null : match ($operation) {
                MediaOperation::VideoEncode, MediaOperation::VideoStreamCopy => 'video',
                default => 'audio',
            });
        }
    }
}
