<?php

namespace App\Http\Resources\Album;

use App\Http\Resources\Artist\ArtistResource;
use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Album
 */
class AlbumWithoutSongsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title'       => $this->title,
            'slug'        => $this->slug,
            'year'        => $this->year,
            'directory'   => $this->directory,
            'coverUrl'    => $this->whenLoaded('cover', fn() => route('api.image.serve', ['image' => $this->cover])),
            'createdAt'   => $this->created_at,
            'updatedAt'   => $this->updated_at,
            /**
             * Album artist relation
             */
            'albumArtist' => ArtistResource::make($this->whenLoaded('albumArtist')),
        ];
    }
}
