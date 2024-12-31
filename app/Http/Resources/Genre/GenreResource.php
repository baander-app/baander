<?php

namespace App\Http\Resources\Genre;

use App\Http\Resources\Song\SongResource;
use App\Models\Genre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Genre
 */
class GenreResource extends JsonResource
{
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
             * Songs relation
             */
            'songs' => SongResource::collection($this->whenLoaded('songs')),
        ];
    }
}
