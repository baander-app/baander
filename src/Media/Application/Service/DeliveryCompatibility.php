<?php

declare(strict_types=1);

namespace App\Media\Application\Service;

use App\Media\Application\DTO\DeliveryDecision;
use App\Media\Application\DTO\DeliveryDestination;
use App\Media\Application\DTO\DeliveryKind;
use App\Media\Application\DTO\DeliverySelection;
use App\Media\Application\DTO\DeliverySource;
use App\Transcode\Application\DTO\MediaOperation;
use App\Transcode\Application\Port\MediaExecutionPolicyInterface;

final readonly class DeliveryCompatibility
{
    public function __construct(private MediaExecutionPolicyInterface $policy)
    {
    }

    public function decide(DeliverySource $source, DeliveryDestination $destination): DeliveryDecision
    {
        if ($destination->sourcePlaybackFailed) {
            return new DeliveryDecision(!$source->audioSelectionRequiresRemux && $this->policy->permits($source->video ? MediaOperation::VideoEncode : MediaOperation::AudioEncode)
                ? DeliveryKind::Encoded : DeliveryKind::Incompatible, 'browser_rejected_source');
        }
        if ($source->hasSubtitles && array_intersect(['hls', 'dash'], $destination->transports) === []) {
            return new DeliveryDecision(DeliveryKind::Incompatible, 'subtitle_delivery_unsupported');
        }
        $reason = $this->encodingReason($source, $destination);
        if ($reason !== null) {
            return new DeliveryDecision(!$source->audioSelectionRequiresRemux && $this->policy->permits($source->video ? MediaOperation::VideoEncode : MediaOperation::AudioEncode)
                ? DeliveryKind::Encoded : DeliveryKind::Incompatible, $reason);
        }
        if (!$source->audioSelectionRequiresRemux && !$source->hasSubtitles && in_array('file', $destination->transports, true) && $source->container !== null && in_array($source->container, $destination->containers, true)) {
            return new DeliveryDecision(DeliveryKind::Original);
        }
        if (($source->video && $source->videoStreamIndex === null) || ($source->audioCodec !== null && $source->audioStreamIndex === null)) {
            return new DeliveryDecision(DeliveryKind::Incompatible, 'source_stream_metadata_unavailable');
        }
        foreach ($destination->containers as $container) {
            if ($this->canMux($container, $source) && (!$source->video || array_intersect(['hls', 'dash'], $destination->transports) !== [])) {
                $transport = $source->video ? (in_array('hls', $destination->transports, true) ? 'hls' : 'dash') : 'file';
                return new DeliveryDecision(DeliveryKind::Copy, selection: new DeliverySelection($container, $source->videoStreamIndex, $source->audioStreamIndex, $transport));
            }
        }
        if ($source->audioSelectionRequiresRemux) {
            return new DeliveryDecision(DeliveryKind::Incompatible, 'audio_stream_selection_requires_remux');
        }
        return new DeliveryDecision($this->policy->permits($source->video ? MediaOperation::VideoEncode : MediaOperation::AudioEncode)
            ? DeliveryKind::Encoded : DeliveryKind::Incompatible, 'container_unsupported');
    }

    public function isCompatible(DeliverySource $source, DeliveryDestination $destination): bool
    {
        return $this->encodingReason($source, $destination) === null
            && $source->container !== null
            && in_array($source->container, $destination->containers, true);
    }

    private function encodingReason(DeliverySource $source, DeliveryDestination $destination): ?string
    {
        if ($source->video && ($source->bitDepth === null || $source->hdr === null || $source->pixelFormat === null)) {
            return 'source_color_metadata_unavailable';
        }
        if ($source->video && !in_array($source->pixelFormat, $destination->pixelFormats, true)) {
            return 'video_pixel_format_unsupported';
        }
        if ($source->video && $source->bitDepth > $destination->maxBitDepth) {
            return 'video_bit_depth_unsupported';
        }
        if ($source->video && $source->hdr && !$destination->supportsHdr) {
            return 'video_hdr_unsupported';
        }
        if ($destination->subtitleBurnIn) {
            return 'subtitle_burn_in_required';
        }
        if ($source->video && ($source->videoCodec === null || !in_array($source->videoCodec, $destination->videoCodecs, true))) {
            return 'video_codec_unsupported';
        }
        if ($source->video && (($destination->maxWidth !== null && ($source->width === null || $source->width > $destination->maxWidth))
            || ($destination->maxHeight !== null && ($source->height === null || $source->height > $destination->maxHeight)))) {
            return 'video_dimensions_unsupported';
        }
        if ($source->audioCodec !== null && !in_array($source->audioCodec, $destination->audioCodecs, true)) {
            return 'audio_codec_unsupported';
        }
        if ($source->audioCodec !== null && $destination->maxAudioChannels !== null
            && ($source->audioChannels === null || $source->audioChannels > $destination->maxAudioChannels)) {
            return 'audio_channels_unsupported';
        }
        if (!$source->video && $source->audioCodec === null) {
            return 'source_stream_metadata_unavailable';
        }
        return null;
    }

    private function canMux(string $container, DeliverySource $source): bool
    {
        return $container === 'mp4'
            && (!$source->video || $source->videoCodec === 'h264')
            && ($source->audioCodec === null || $source->audioCodec === 'aac');
    }
}
