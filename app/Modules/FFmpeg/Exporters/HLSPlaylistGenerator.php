<?php

namespace App\Modules\FFmpeg\Exporters;

use Illuminate\Support\Collection;
use App\Modules\FFmpeg\Drivers\PHPFFMpeg;
use App\Modules\FFmpeg\Filesystem\Media;
use App\Modules\FFmpeg\Http\DynamicHLSPlaylist;
use App\Modules\FFmpeg\MediaOpener;
use App\Modules\FFmpeg\Support\StreamParser;

class HLSPlaylistGenerator implements PlaylistGenerator
{
    public const string PLAYLIST_START = '#EXTM3U';
    public const string PLAYLIST_END = '#EXT-X-ENDLIST';

    /**
     * Return the line from the master playlist that references the given segment playlist.
     *
     * @param Media $segmentPlaylistMedia
     * @param string $key
     * @return string
     */
    private function getStreamInfoLine(Media $segmentPlaylistMedia, string $key): string
    {
        $segmentPlaylist = $segmentPlaylistMedia->getDisk()->get(
            $segmentPlaylistMedia->getDirectory() . HLSExporter::generateTemporarySegmentPlaylistFilename($key),
        );

        $lines = DynamicHLSPlaylist::parseLines($segmentPlaylist)->filter();

        return $lines->get($lines->search($segmentPlaylistMedia->getFilename()) - 1);
    }

    /**
     * Loops through all segment playlists and generates a main playlist. It finds
     * the relative paths to the segment playlists and adds the framerate when
     * to each playlist.
     *
     * @param array $segmentPlaylists
     * @param PHPFFMpeg $driver
     * @return string
     */
    public function get(array $segmentPlaylists, PHPFFMpeg $driver): string
    {
        return Collection::make($segmentPlaylists)->map(function (Media $segmentPlaylist, $key) use ($driver) {
            $streamInfoLine = $this->getStreamInfoLine($segmentPlaylist, $key);

            $media = new MediaOpener($segmentPlaylist->getDisk(), $driver)
                ->openWithInputOptions($segmentPlaylist->getPath(), ['-allowed_extensions', 'ALL']);

            if ($media->getVideoStream()) {
                if ($frameRate = StreamParser::new($media->getVideoStream())->getFrameRate()) {
                    $streamInfoLine .= ",FRAME-RATE={$frameRate}";
                }
            }

            return [$streamInfoLine, $segmentPlaylist->getFilename()];
        })->collapse()
            ->prepend(static::PLAYLIST_START)
            ->push(static::PLAYLIST_END)
            ->implode(PHP_EOL);
    }
}
