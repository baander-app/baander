<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\Service\AudioProcessingRules;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\LoudnessStandard;
use App\Transcode\Domain\ValueObject\VideoProbeResult;

final class AudioFilterBuilder
{
    /** @var list<string> */
    private array $filters = [];

    public static function create(): self
    {
        return new self();
    }

    public function downmix(VideoProbeResult $probe, AudioProfile $profile): self
    {
        if (!$profile->downmixSurround) {
            return $this;
        }

        $downmix = AudioProcessingRules::downmixFilter($probe->audioChannels);
        if ($downmix !== '') {
            $this->filters[] = $downmix;
        }

        return $this;
    }

    /**
     * Apply dialogue enhancement for surround-downmixed content.
     *
     * Only applies when the source had surround channels (> 2) AND
     * the profile is set to downmix surround to stereo. Without the
     * downmix guard, dialogue EQ boost would be applied to full 5.1
     * surround output, which is incorrect.
     */
    public function dialogueEnhancement(VideoProbeResult $probe, AudioProfile $profile, float $gainDb = 3.0): self
    {
        if (!$probe->isSurround() || !$profile->downmixSurround) {
            return $this;
        }

        $this->filters[] = AudioProcessingRules::dialogueEnhancementFilter($gainDb);

        return $this;
    }

    public function loudness(LoudnessStandard $standard, array $measured = []): self
    {
        $this->filters[] = AudioProcessingRules::loudnessFilter($standard, $measured);

        return $this;
    }

    public function drc(AudioProfile $profile): self
    {
        if (!$profile->applyDrc) {
            return $this;
        }

        $this->filters[] = AudioProcessingRules::drcFilter($profile->drcRatio, $profile->drcThreshold);

        return $this;
    }

    public function channelLayout(AudioProfile $profile): self
    {
        $this->filters[] = AudioProcessingRules::channelLayoutFilter($profile->channelCount);

        return $this;
    }

    public function resample(VideoProbeResult $probe, AudioProfile $profile): self
    {
        $resample = AudioProcessingRules::resampleFilter($probe->audioSampleRate, $profile->sampleRate);
        if ($resample !== '') {
            $this->filters[] = $resample;
        }

        return $this;
    }

    public function build(): string
    {
        return implode(',', $this->filters);
    }
}
