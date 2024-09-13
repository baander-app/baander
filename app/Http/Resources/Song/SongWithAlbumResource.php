<?php

namespace App\Http\Resources\Song;

use App\Http\Resources\Album\AlbumWithoutSongsResource;
use Illuminate\Http\Request;

class SongWithAlbumResource extends SongResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        return array_merge($data, [
            /**
             * Album relation
             */
            'album' => AlbumWithoutSongsResource::make($this->whenLoaded('album')),
        ]);
    }
}
