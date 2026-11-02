<?php

declare(strict_types=1);

namespace App\Session\Application\DTO;

final class PlaybackRejectionReason
{
    public const array VALUES = [
        'source_color_metadata_unavailable',
        'video_pixel_format_unsupported',
        'video_bit_depth_unsupported',
        'video_hdr_unsupported',
        'subtitle_burn_in_required',
        'video_codec_unsupported',
        'video_dimensions_unsupported',
        'audio_codec_unsupported',
        'audio_channels_unsupported',
        'source_stream_metadata_unavailable',
        'audio_stream_selection_requires_remux',
        'container_unsupported',
        'audio_stream_unavailable',
        'audio_transcoding_disabled',
        'destination_media_unsupported',
        'subtitle_delivery_unsupported',
        'browser_rejected_source',
        'unknown',
    ];
}
