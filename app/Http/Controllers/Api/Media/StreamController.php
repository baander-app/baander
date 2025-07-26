<?php

namespace App\Http\Controllers\Api\Media;

use App\Models\{Song, TokenAbility};
use Spatie\RouteAttributes\Attributes\{Get, Prefix};
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[Prefix('/stream')]
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
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_STREAM->value,
    ])]
    public function songDirect(Song $song)
    {
        return response()->file($song->getPath());
    }
}