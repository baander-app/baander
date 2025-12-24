<?php

namespace App\Http\Controllers\Api\Media;

use Dedoc\Scramble\Attributes\Group;
use App\Models\Song;
use Spatie\RouteAttributes\Attributes\{Get, Prefix};
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[Prefix('/stream')]
#[Group('Media')]
class StreamController
{
    /**
     * Direct stream the song.
     * Requires token with "access-stream"
     *
     * @param Song $song
     * @return BinaryFileResponse
     */
    #[Get('/song/{song}/direct', 'api.stream.song-direct', [
        'auth:oauth',
        'scope:access-stream',
    ])]
    public function songDirect(Song $song)
    {
        return response()->file($song->getPath());
    }
}