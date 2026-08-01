<?php

declare(strict_types=1);

namespace App\Transcode\Application\Command;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionPriority;

final readonly class CreateTranscodeSessionCommand
{
    /**
     * @param string[] $audioLanguages BCP-47 language tags for audio tracks to encode
     */
    public function __construct(
        private Uuid $userId,
        private Uuid $videoId,
        private QualityTier $qualityTier,
        private AudioProfile $audioProfile,
        private SessionPriority $priority = SessionPriority::Normal,
        private array $audioLanguages = [],
    ) {
    }

    public function getUserId(): Uuid { return $this->userId; }
    public function getVideoId(): Uuid { return $this->videoId; }
    public function getQualityTier(): QualityTier { return $this->qualityTier; }
    public function getAudioProfile(): AudioProfile { return $this->audioProfile; }
    public function getPriority(): SessionPriority { return $this->priority; }

    /** @return string[] */
    public function getAudioLanguages(): array { return $this->audioLanguages; }
}
