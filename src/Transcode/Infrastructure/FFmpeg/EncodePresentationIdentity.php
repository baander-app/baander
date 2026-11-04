<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;

final class EncodePresentationIdentity
{
    /** @param list<string> $audioLanguages */
    public static function derive(string $sourceHash, VideoProbeResult $probe, QualityTier $tier, AudioProfile $audioProfile, array $audioLanguages, SegmentEncoder $encoder): PresentationSelection
    {
        $audio = $probe->selectedAudioStream();
        if ($sourceHash === '' || $probe->videoStreamIndex === null
            || ($probe->hasAudio() && ($audio === null || $audio->streamIndex === null))) {
            throw new TranscodeStartupUnavailableException();
        }
        $arguments = $encoder->buildStreamArgs(
            '/source',
            $tier,
            $encoder->buildVideoFilters($probe, $tier),
            '/presentation',
            audioStreamIndex: $probe->selectedAudioStreamOrdinal() ?? 0,
            audioProfile: $audioProfile,
            audioFilters: $encoder->buildAudioFiltersForProfile($probe, $audioProfile),
            hasAudio: $probe->hasAudio(),
            sourceFrameRate: $probe->framerate,
            sourceTimestampOrigin: $probe->sourceTimestampOrigin,
            sourceDuration: $probe->duration,
        );
        $fingerprint = json_encode([
            'arguments' => $arguments,
            'audioProfile' => $audioProfile->jsonSerialize(),
            'audioLanguages' => $audioLanguages,
        ], JSON_THROW_ON_ERROR) |> (static fn (string $json): string => hash('sha256', $json));
        return new PresentationSelection('encode', $sourceHash, $probe->videoStreamIndex, $audio?->streamIndex, 'mp4', $fingerprint);
    }
}
