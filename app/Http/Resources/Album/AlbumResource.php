<?php

namespace App\Http\Resources\Album;

use App\Http\Resources\Artist\ArtistResource;
use App\Http\Resources\HasJsonCollection;
use App\Http\Resources\Image\ImageResource;
use App\Http\Resources\Song\SongResource;
use App\Models\Album;
use Illuminate\Http\Request;

/**
 * @mixin Album
 */
class AlbumResource extends AlbumWithoutSongsResource
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
            'title'          => $this->title,
            'year'           => $this->year,
            'type'           => $this->type,
            'label'          => $this->label,
            'catalogNumber'  => $this->catalog_number,
            'barcode'        => $this->barcode,
            'country'        => $this->country,
            'language'       => $this->language,
            'disambiguation' => $this->disambiguation,
            'annotation'     => $this->annotation,
            'spotifyId'      => $this->spotify_id,
            'mbid'           => $this->mbid,
            'discogsId'      => $this->discogs_id,
            'createdAt'      => $this->created_at,
            'updatedAt'      => $this->updated_at,
            /**
             * Cover relation
             */
            'cover'          => ImageResource::make($this->whenLoaded('cover')),
            /**
             * Album artists relation
             */
            'artists'        => ArtistResource::collection($this->whenLoaded('artists')),
            /**
             * Songs relation
             */
            'songs'          => SongResource::collection($this->whenLoaded('songs')),
            /**
             * @var array{
             *   slug: string,
             *   name: string
             * }[]
             */
            'genres'         => $this->whenLoaded('songs', function () {
                return $this->songs->flatMap(fn($song) => $song->genres)->unique('id')->values()->map(fn($genre) => [
                    'slug' => $genre->slug,
                    'name' => $genre->name,
                ]);
            }),
            'lockedFields'   => $this->locked_fields,
        ];
    }
}
