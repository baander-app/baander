<?php

namespace Baander\Transcoder\Playlist;

use Baander\Common\Streaming\AudioProfile;
use Baander\Common\Streaming\VideoProfile;
use Baander\Transcoder\Probe\Models\AudioStream;
use Baander\Transcoder\Probe\Models\FFprobeMetadata;
use Baander\Transcoder\Probe\Models\VideoStream;
use Baander\Transcoder\Probe\Probe;

class PlaylistManager
{
    private string $outputDirectory;
    private FFprobeMetadata $metadata;


    public function __construct(
        private readonly Probe $probe,
    )
    {
    }

    /**
     * Analyzes a media file and creates playlists.
     *
     * @param string $filePath The media file path to be analyzed.
     * @param array|null $profiles Optional array of [AudioProfile, VideoProfile] pairs.
     * @param int $segmentDuration The duration of each segment in seconds (default = 4 seconds).
     * @return array<string, string> Paths to the generated playlists (master, variants, audio).
     * @throws \RuntimeException If the analysis or playlist generation fails.
     */
    public function create(
        string $filePath,
        string $outputDirectory,
        ?array $profiles = null,
        int    $segmentDuration = 4,
    ): array
    {
        $this->outputDirectory = $outputDirectory;

        try {
            $metadata = $this->probe->analyze($filePath);
            $playlists = [];

            // Create master playlist
            $playlists['master'] = $profiles
                ? $this->createMasterPlaylistFromProfiles($profiles)
                : $this->createMasterPlaylist($metadata);

            // Process profiles if provided
            if ($profiles) {
                foreach ($profiles as $index => $profilePair) {
                    $this->validateProfileStructure($profilePair, $index);
                    [$audioProfile, $videoProfile] = $profilePair;

                    $playlists["variant_{$videoProfile->width}x{$videoProfile->height}"] =
                        $this->createVariantPlaylistFromProfile($filePath, $videoProfile, $segmentDuration);

                    $playlists["audio_{$index}"] =
                        $this->createAudioPlaylistFromProfile($metadata, $filePath, $audioProfile, $segmentDuration);
                }
            } else {
                $playlists = array_merge($playlists, $this->createPlaylistsFromMetadata($metadata, $segmentDuration));
            }

            return $playlists;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to analyze file and create playlists: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generates the master playlist from profiles.
     */
    private function createMasterPlaylistFromProfiles(array $profiles): string
    {
        $masterPlaylistPath = $this->outputDirectory . '/master.m3u8';
        $content = "#EXTM3U\n#EXT-X-VERSION:7\n";

        foreach ($profiles as $index => [$audioProfile, $videoProfile]) {
            $groupId = "audio-group-$index";
            $content .= "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"$groupId\",NAME=\"audio-$index\",LANGUAGE=\"und\",AUTOSELECT=YES,URI=\"audio_{$index}.m3u8\"\n";

            $content .= '#EXT-X-STREAM-INF:BANDWIDTH=' . ($videoProfile->bitrate ?? 0) .
                ",RESOLUTION={$videoProfile->width}x{$videoProfile->height}" .
                ($videoProfile->codec ? ",CODECS=\"{$videoProfile->codec}\"" : '') .
                ",AUDIO=\"$groupId\"\n";

            $content .= "variant_{$videoProfile->width}x{$videoProfile->height}.m3u8\n";
        }

        file_put_contents($masterPlaylistPath, $content);

        return $masterPlaylistPath;
    }

    /**
     * Generates the master playlist from metadata.
     */
    public function createMasterPlaylist(FFprobeMetadata $metadata): string
    {
        $masterPlaylistPath = $this->outputDirectory . DIRECTORY_SEPARATOR . 'master.m3u8';
        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:7\n";

        // Extract audio tracks
        $audioTracks = array_filter($metadata->streams, fn($stream) => $stream instanceof AudioStream);
        foreach ($audioTracks as $index => $audioTrack) {
            $groupId = "audio-group-$index";
            $language = $audioTrack->tags['language'] ?? 'und';
            $content .= "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"$groupId\",NAME=\"$language\",LANGUAGE=\"$language\",AUTOSELECT=YES,URI=\"audio_$language.m3u8\"\n";
        }

        // Extract video streams
        $videoStreams = array_filter($metadata->streams, fn($stream) => $stream instanceof VideoStream);
        foreach ($videoStreams as $videoStream) {
            $bandwidth = $videoStream->bitRate ?? 0;
            $resolution = "{$videoStream->width}x{$videoStream->height}";
            $variantPath = "variant_{$resolution}.m3u8";
            $audioGroupId = 'audio-group-0';

            $content .= "#EXT-X-STREAM-INF:BANDWIDTH=$bandwidth,RESOLUTION=$resolution,AUDIO=\"$audioGroupId\"\n";
            $content .= $variantPath . "\n";
        }

        file_put_contents($masterPlaylistPath, $content);

        return $masterPlaylistPath;
    }

    /**
     * Creates playlists based on metadata streams.
     */
    private function createPlaylistsFromMetadata(FFprobeMetadata $metadata, int $segmentDuration): array
    {
        $playlists = [];

        foreach ($metadata->streams as $stream) {
            if ($stream instanceof VideoStream) {
                $playlists["variant_{$stream->width}x{$stream->height}"] =
                    $this->createVariantPlaylist($stream, $segmentDuration);
            } else if ($stream instanceof AudioStream) {
                $language = $stream->tags['language'] ?? 'und';
                $playlists["audio_$language"] =
                    $this->createAudioPlaylist($stream, $segmentDuration);
            }
        }

        return $playlists;
    }

    /**
     * Creates a video variant playlist based on video stream metadata.
     *
     * @param VideoStream $stream The video stream metadata.
     * @param int $segmentDuration The duration of each segment in seconds.
     * @return string Path to the generated video variant playlist.
     */
    private function createVariantPlaylist(VideoStream $stream, int $segmentDuration): string
    {
        $variantPlaylistPath = $this->outputDirectory . "/variant_{$stream->width}x{$stream->height}.m3u8";

        $duration = $stream->duration ?? 0;
        $segments = $this->calculateSegments($duration, $segmentDuration);

        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:7\n";
        $content .= "#EXT-X-TARGETDURATION:$segmentDuration\n";
        $content .= "#EXT-X-PLAYLIST-TYPE:VOD\n";
        $content .= "#EXT-X-MEDIA-SEQUENCE:0\n";

        foreach ($segments as $index => $segment) {
            $content .= "#EXTINF:$segment,\n";
            $content .= "segment_{$stream->width}x{$stream->height}_$index.ts\n";
        }

        $content .= "#EXT-X-ENDLIST\n";

        file_put_contents($variantPlaylistPath, $content);

        return $variantPlaylistPath;
    }

    /**
     * Creates an audio playlist based on audio stream metadata.
     *
     * @param AudioStream $stream The audio stream metadata.
     * @param int $segmentDuration The duration of each segment in seconds.
     * @return string Path to the generated audio playlist.
     */
    private function createAudioPlaylist(AudioStream $stream, int $segmentDuration): string
    {
        $language = $stream->tags['language'] ?? 'und';
        $audioPlaylistPath = $this->outputDirectory . "/audio_$language.m3u8";

        $duration = $stream->duration ?? 0;
        $segments = $this->calculateSegments($duration, $segmentDuration);

        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:7\n";
        $content .= "#EXT-X-TARGETDURATION:$segmentDuration\n";
        $content .= "#EXT-X-MEDIA-SEQUENCE:0\n";

        foreach ($segments as $index => $segment) {
            $content .= "#EXTINF:$segment,\n";
            $content .= "audio_segment_$index.ts\n";
        }

        $content .= "#EXT-X-ENDLIST\n";

        file_put_contents($audioPlaylistPath, $content);

        return $audioPlaylistPath;
    }

    /**
     * Validates the structure of profiles.
     */
    private function validateProfileStructure(array $profilePair, int $index): void
    {
        [$audioProfile, $videoProfile] = $profilePair;

        if (!$audioProfile instanceof AudioProfile || !$videoProfile instanceof VideoProfile) {
            throw new \InvalidArgumentException("Profile structure at index $index contains invalid types. Expected [AudioProfile, VideoProfile].");
        }
    }

    /**
     * Calculates segment durations.
     */
    private function calculateSegments(float $totalDuration, int $segmentDuration): array
    {
        $segments = [];
        $remainingDuration = $totalDuration;

        while ($remainingDuration > 0) {
            $segments[] = min($remainingDuration, $segmentDuration);
            $remainingDuration -= $segmentDuration;
        }

        return $segments;
    }

    /**
     * Creates a video variant playlist from a profile.
     */
    private function createVariantPlaylistFromProfile(
        FFprobeMetadata $metadata,
        VideoProfile    $videoProfile,
        int             $segmentDuration,
    ): string
    {
        $variantPlaylistPath = $this->outputDirectory . "/variant_{$videoProfile->width}x{$videoProfile->height}.m3u8";
        $duration = $metadata->format->duration ?? 0;
        $segments = $this->calculateSegments($duration, $segmentDuration);

        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:7\n";
        $content .= "#EXT-X-TARGETDURATION:$segmentDuration\n";
        $content .= "#EXT-X-PLAYLIST-TYPE:VOD\n";
        $content .= "#EXT-X-MEDIA-SEQUENCE:0\n";

        foreach ($segments as $index => $segment) {
            $content .= "#EXTINF:$segment,\n";
            $content .= "segment_{$videoProfile->width}x{$videoProfile->height}_$index.ts\n";
        }

        $content .= "#EXT-X-ENDLIST\n";

        file_put_contents($variantPlaylistPath, $content);

        return $variantPlaylistPath;
    }

    /**
     * Creates an audio playlist from a profile.
     */
    private function createAudioPlaylistFromProfile(
        FFprobeMetadata $metadata,
        AudioProfile    $audioProfile,
        int             $segmentDuration,
    ): string
    {
        $audioPlaylistPath = $this->outputDirectory . DIRECTORY_SEPARATOR . "audio_{$audioProfile->bitrate}.m3u8";

        $duration = $metadata->format->duration ?? 0;
        $segments = $this->calculateSegments($duration, $segmentDuration);

        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:7\n";
        $content .= "#EXT-X-TARGETDURATION:$segmentDuration\n";
        $content .= "#EXT-X-MEDIA-SEQUENCE:0\n";

        foreach ($segments as $index => $segment) {
            $content .= "#EXTINF:$segment,\n";
            $content .= "audio_segment_$index.ts\n";
        }

        $content .= "#EXT-X-ENDLIST\n";

        file_put_contents($audioPlaylistPath, $content);

        return $audioPlaylistPath;
    }
}