<?php

namespace App\Http\Resources\Album;

use App\Http\Resources\Artist\ArtistResource;
use App\Http\Resources\HasJsonCollection;
use App\Http\Resources\Image\ImageResource;
use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Album
 */
class AlbumWithoutSongsResource extends JsonResource
{
    use HasJsonCollection;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'publicId'  => $this->public_id,
            'title'     => $this->title,
            'year'      => $this->year,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
            /**
             * Cover relation
             */
            'cover'     => ImageResource::make($this->whenLoaded('cover')),
            /**
             * Album artist relation
             */
            'artists'   => ArtistResource::make($this->whenLoaded('artists')),
        ];
    }
}
