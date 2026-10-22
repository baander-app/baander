<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Shared;

/**
 * The video delivery routes a player fetches with a signed URL instead of a DPoP proof,
 * with a request path for each. The firewall grants them public access and the proof and
 * rate-limit listeners exempt them; `DeliveryRouteInventoryTest` keeps this list, those
 * exemptions and the firewall rows aligned with the compiled router.
 */
final class SignedDeliveryRoutes
{
    /**
     * @return array<string, string> route name => request path, including the query a
     *                               segment request needs
     */
    public static function paths(string $videoId, string $jobId, string $language = 's2', string $track = 'video'): array
    {
        return [
            'stream_master_manifest' => "/api/transcode/$videoId/master.m3u8",
            'stream_dash_manifest' => "/api/transcode/$videoId/manifest.mpd",
            'stream_media_manifest' => "/api/transcode/$jobId/tracks/$track/media.m3u8",
            'stream_subtitle_manifest' => "/api/transcode/$jobId/subtitles/$language/media.m3u8",
            'stream_segment_init_segment' => "/api/transcode/$jobId/tracks/$track/init.mp4",
            'stream_segment_segment' => "/api/transcode/$jobId/tracks/$track/segment.m4s?index=0",
            'stream_segment_subtitle_segment' => "/api/transcode/$jobId/subtitles/$language/0.vtt",
        ];
    }
}
