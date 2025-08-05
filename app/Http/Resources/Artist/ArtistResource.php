<?php

namespace App\Http\Resources\Artist;

use App\Http\Resources\HasJsonCollection;
use App\Http\Resources\Image\ImageResource;
use App\Models\Artist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Artist
 */
class ArtistResource extends JsonResource
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
            'publicId'       => $this->public_id,
            'name'           => $this->name,
            'sortName'       => $this->sort_name,
            'country'        => $this->country,
            'type'           => $this->type,
            'lifeSpanBegin'  => $this->life_span_begin,
            'lifeSpanEnd'    => $this->life_span_end,
            'disambiguation' => $this->disambiguation,
            'mbid'           => $this->mbid,
            'discogsId'      => $this->discogs_id,
            'spotifyId'      => $this->spotify_id,
            'createdAt'      => $this->created_at,
            'updatedAt'      => $this->updated_at,
            /**
             * Portrait relation
             */
            'portrait'       => ImageResource::make($this->whenLoaded('portrait')),
        ];
    }
}
