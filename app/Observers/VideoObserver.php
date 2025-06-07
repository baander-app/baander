<?php

namespace App\Observers;

use App\Http\Integrations\Transcoder\TranscoderClient;
use App\Models\Video;

class VideoObserver
{
    /**
     * Handle the Video "created" event.
     */
    public function created(Video $video): void
    {
        $service = app(TranscoderClient::class);

        $service->enqueueProbe($video->path);
    }

    public function updating(Video $video)
    {
        if ($video->wasChanged('probe')) {
            if (isset($video->probe['video']['width']) && isset($video->probe['video']['height'])) {
                $video->width = (int)$video->probe['video']['width'];
                $video->height = (int)$video->probe['video']['height'];
            }

            if (isset($video->probe['video']['frame_rate'])) {
                $video->framerate = (int)$video->probe['video']['frame_rate'];
            }

            if (isset($video->probe['duration_seconds'])) {
                $video->duration = floor($video->probe['duration_seconds']);
            }

            if (isset($video->probe['video']['bitrate'])) {
                $video->video_bitrate = (int)$video->probe['video']['bitrate'];
            }
        }
    }
}
