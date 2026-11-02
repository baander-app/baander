<?php

declare(strict_types=1);

namespace App\Media\Application\Service;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\DTO\DeliveryDecision;
use App\Media\Application\DTO\DeliveryDestination;
use App\Media\Application\DTO\DeliveryKind;
use App\Media\Application\DTO\DeliverySource;
use App\Media\Application\DTO\DeliverySelection;
use App\Media\Application\Port\DeliveryDecisionPortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\MediaOperation;
use App\Transcode\Application\Port\MediaExecutionPolicyInterface;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Port\CompletedVideoArtifactPort;
use App\Transcode\Application\Port\CopyPresentationPortInterface;
use App\Transcode\Application\DTO\CopyPresentationInput;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;

final readonly class DeliveryDecisionService implements DeliveryDecisionPortInterface
{
    public function __construct(
        private PlaybackMediaAccessInterface $access,
        private MediaReadScopeProviderInterface $scopes,
        private VideoPortInterface $videos,
        private StreamPortInterface $streams,
        private DeliveryCompatibility $compatibility,
        private MediaExecutionPolicyInterface $policy,
        private AudioRenditionPortInterface $audio,
        private SystemSettingsPortInterface $settings,
        private ?CompletedVideoArtifactPort $videoArtifacts = null,
        private ?CopyPresentationPortInterface $copyPresentations = null,
    ) {
    }

    public function video(Uuid $owner, PublicId $id, DeliveryDestination $destination, ?string $requestId = null): DeliveryDecision
    {
        $this->access->assertVideoAccess($owner, $id);
        $source = $this->videos->getSourceMetadata($id, $this->scopes->forUser($owner)->getLibraries())
            ?? throw new ConflictException('Playback media is unavailable.');
        $defaultAudio = null;
        $selected = null;
        foreach ($source->audioStreams as $stream) {
            if ($defaultAudio === null || (($stream['isDefault'] ?? false) && !($defaultAudio['isDefault'] ?? false))) {
                $defaultAudio = $stream;
            }
            if ($destination->audioStreamIndex !== null && ($stream['streamIndex'] ?? null) === $destination->audioStreamIndex) {
                $selected = $stream;
            }
        }
        if ($destination->audioStreamIndex === null) {
            $selected = $defaultAudio;
        }
        if ($destination->audioStreamIndex !== null && $selected === null) {
            return new DeliveryDecision(DeliveryKind::Incompatible, 'audio_stream_unavailable');
        }
        $media = new DeliverySource(
            $this->container($source->containerFormat, $source->mimeType),
            $source->videoCodec,
            $source->videoStreamIndex,
            is_string($selected['codec'] ?? null) ? $selected['codec'] : null,
            is_int($selected['streamIndex'] ?? null) ? $selected['streamIndex'] : null,
            is_int($selected['channels'] ?? null) ? $selected['channels'] : null,
            $source->width,
            $source->height,
            bitDepth: $this->bitDepth($source->pixFmt),
            hdr: $this->hdr($source->colorSpace),
            pixelFormat: $source->pixFmt,
            audioSelectionRequiresRemux: $destination->audioStreamIndex !== null && $destination->audioStreamIndex !== ($defaultAudio['streamIndex'] ?? null)
        );
        $decision = $this->compatibility->decide($media, $destination);
        if ($decision->kind === DeliveryKind::Original || $destination->subtitleBurnIn) {
            return $decision;
        }
        $artifacts = $media->videoStreamIndex === null ? [] : ($this->videoArtifacts?->findComplete($owner, $source->videoId, $source->hash, $media->videoStreamIndex, $media->audioStreamIndex) ?? []);
        foreach ($artifacts as $artifact) {
            if ($artifact->sourceVideoStreamIndex !== $media->videoStreamIndex || $artifact->sourceAudioStreamIndex !== $media->audioStreamIndex
                || !in_array($artifact->transport, $destination->transports, true)) {
                continue;
            }
            $candidate = new DeliverySource(
                $artifact->container,
                $artifact->videoCodec,
                null,
                $artifact->audioCodec,
                null,
                $artifact->audioChannels,
                $artifact->width,
                $artifact->height,
                bitDepth: $artifact->bitDepth,
                hdr: $artifact->hdr,
                pixelFormat: $artifact->pixelFormat
            );
            if ($this->compatibility->isCompatible($candidate, $destination)) {
                return new DeliveryDecision(DeliveryKind::Cached, selection: new DeliverySelection($artifact->container, $artifact->sourceVideoStreamIndex, $artifact->sourceAudioStreamIndex, $artifact->transport), url: $artifact->url, artifact: $artifact);
            }
        }
        if ($decision->kind === DeliveryKind::Copy && $decision->selection !== null && $requestId !== null) {
            if ($this->copyPresentations === null) {
                throw new \LogicException('Copy presentation admission is not configured.');
            }
            $presentation = $this->copyPresentations->start(new CopyPresentationInput($owner, $source->videoId, $source->hash, $decision->selection->videoStreamIndex,
                $decision->selection->audioStreamIndex, $decision->selection->container, $source->width, $source->height, $requestId));
            return new DeliveryDecision(DeliveryKind::Copy, selection: $decision->selection,
                url: '/api/transcode/' . $id->toString() . '/master.m3u8', requestId: $requestId, jobId: $presentation->jobId->toString());
        }
        return $decision;
    }

    private function container(?string $format, string $mime): ?string
    {
        $identified = match ($mime) {
            'video/mp4', 'audio/mp4' => 'mp4', 'video/quicktime' => 'mov',
            'video/webm', 'audio/webm' => 'webm', 'video/x-matroska', 'audio/x-matroska' => 'matroska',
            'audio/mpeg' => 'mp3', 'audio/flac', 'audio/x-flac' => 'flac', 'audio/ogg' => 'ogg',
            'audio/aac' => 'aac', default => null,
        };
        if ($format === null) {
            return $identified;
        }
        if (!str_contains($format, ',')) {
            return $format;
        }
        return $identified !== null && in_array($identified, explode(',', $format), true) ? $identified : null;
    }

    private function bitDepth(?string $format): ?int
    {
        if ($format === null) {
            return null;
        }
        if (preg_match('/(?:p|gray|gbrp)(9|10|12|14|16)(?:le|be)?$/D', $format, $matches) === 1) {
            return (int) $matches[1];
        }
        return in_array($format, ['yuv420p', 'yuv422p', 'yuv444p', 'yuvj420p', 'yuvj422p', 'yuvj444p', 'nv12', 'nv21', 'rgb24', 'bgr24', 'rgba', 'bgra', 'gbrp', 'gray'], true) ? 8 : null;
    }

    /** @param array<string, mixed>|null $colorSpace */
    private function hdr(?array $colorSpace): ?bool
    {
        $transfer = $colorSpace['transfer'] ?? null;
        return !is_string($transfer) || $transfer === 'unknown' || $transfer === 'unspecified' ? null
            : in_array($transfer, ['smpte2084', 'arib-std-b67', 'pq', 'hlg'], true);
    }

    public function track(Uuid $owner, PublicId $id, DeliveryDestination $destination): DeliveryDecision
    {
        $this->access->assertTrackAccess($owner, $id);
        $source = $this->streams->getTrackMetadata($id);
        $path = $this->streams->resolveTrackPath($id);
        if ($destination->audioStreamIndex !== null && $source->streamIndex !== $destination->audioStreamIndex) {
            return new DeliveryDecision(DeliveryKind::Incompatible, 'audio_stream_unavailable');
        }
        $container = $this->container($source->containerFormat, $source->mimeType);
        $decision = $this->compatibility->decide(new DeliverySource(
            $container,
            null,
            null,
            $source->codec,
            $source->streamIndex,
            $source->channels,
            video: false
        ), $destination);
        if ($decision->kind === DeliveryKind::Original) {
            return $decision;
        }
        if ($decision->kind === DeliveryKind::Copy) {
            if ($source->sourceHash === null || $source->streamIndex === null || $source->codec !== 'aac' || $decision->selection?->container !== 'mp4') {
                return new DeliveryDecision(DeliveryKind::Incompatible, 'source_stream_metadata_unavailable');
            }
            $cached = $this->audio->findCompleteCopy($id->toString(), $path, $source->sourceHash, $source->streamIndex);
            $url = '/api/stream/original/track?' . http_build_query(['id' => $id->toString(), 'owner' => $owner->toString(),
                'format' => 'aac', 'mode' => 'copy', 'container' => 'mp4', 'index' => $source->streamIndex]);
            return $cached === null ? $decision->withUrl($url) : new DeliveryDecision(DeliveryKind::Cached, url: $url);
        }
        if ($this->policy->permits(MediaOperation::AudioEncode) && $this->settings->get(TranscodeSettingDefinitions::ENABLED) !== true) {
            return $decision->kind === DeliveryKind::Encoded
                ? new DeliveryDecision(DeliveryKind::Incompatible, 'audio_transcoding_disabled') : $decision;
        }
        $bitrate = (int) $this->settings->get(TranscodeSettingDefinitions::MAX_BITRATE) * 1000;
        foreach (AudioRenditionFormat::cases() as $format) {
            $container = match ($format) {
                AudioRenditionFormat::Mp3 => 'mp3', AudioRenditionFormat::Aac => 'aac', AudioRenditionFormat::Opus => 'ogg'
            };
            if ($this->compatibility->decide(new DeliverySource($container, null, null, $format->value, null, $source->channels, video: false), $destination)->kind !== DeliveryKind::Original) {
                continue;
            }
            if ($this->audio->findComplete($id->toString(), $path, $format, $bitrate) !== null) {
                return new DeliveryDecision(DeliveryKind::Cached, url: '/api/stream/original/track?' . http_build_query(['id' => $id->toString(), 'owner' => $owner->toString(), 'format' => $format->value, 'bitrate' => $bitrate]));
            }
        }
        return $decision;
    }
}
