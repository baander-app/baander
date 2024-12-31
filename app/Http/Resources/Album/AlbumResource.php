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
            'title'       => $this->title,
            'slug'        => $this->slug,
            'year'        => $this->year,
            'directory'   => $this->directory,
            'createdAt'   => $this->created_at,
            'updatedAt'   => $this->updated_at,
            /**
             * Cover relation
             */
            'coverUrl'    => $this->whenLoaded('cover', fn() => route('api.image.serve', ['image' => $this->cover])),
            /**
             * Album artist relation
             */
            'albumArtist' => ArtistResource::make($this->whenLoaded('albumArtist')),
            /**
             * Songs relation
             */
            'songs'       => SongResource::collection($this->whenLoaded('songs')),
            /**
             * @var array{
             *   slug: string,
             *   name: string
             * }[]
             */
            'genres'      => $this->whenLoaded('songs', function () {
                return $this->songs->flatMap(fn($song) => $song->genres)->unique('id')->values()->map(fn($genre)
                    => [
                    'slug' => $genre->slug,
                    'name' => $genre->name,
                ]);
            }),
        ];
    }
}
