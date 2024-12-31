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
            'name'      => $this->name,
            'slug'      => $this->slug,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
            /**
             * Portrait relation
             */
            'portrait'  => ImageResource::make($this->whenLoaded('portrait')),
        ];
    }
}
