<?php

namespace App\Http\Resources\Song;

use App\Format\Bytes;
use App\Format\Duration;
use App\Http\Resources\Album\AlbumWithoutSongsResource;
use App\Http\Resources\Artist\ArtistResource;
use App\Http\Resources\Genre\GenreResource;
use App\Http\Resources\HasJsonCollection;
use App\Models\Song;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Song
 */
class SongResource extends JsonResource
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
            'publicId'      => $this->public_id,
            'title'         => $this->title,
            'year'          => $this->year,
            'comment'       => $this->comment,
            'explicit'      => $this->explicit,
            'disc'          => $this->disc,
            'length'        => $this->length,
            'durationHuman' => (new Duration)->humanize($this->length),
            'lyrics'        => $this->lyrics,
            'lyricsExist'   => (bool)$this->lyrics,
            'path'          => $this->path,
            'track'         => $this->track,
            'size'          => $this->size,
            'sizeHuman'     => Bytes::format($this->size),
            'mimeType'      => $this->mime_type,
            'hash'          => $this->hash,
            'mbid'          => $this->mbid,
            'discogsId'     => $this->discogs_id,
            'spotifyId'     => $this->spotify_id,
            'streamUrl'     => route('api.stream.song-direct', ['song' => $this->public_id]),
            'createdAt'     => $this->created_at,
            'updatedAt'     => $this->updated_at,
            'album'         => AlbumWithoutSongsResource::make($this->whenLoaded('album')),
            'artists'       => ArtistResource::collection($this->whenLoaded('artists')),
            'genres'        => GenreResource::collection($this->whenLoaded('genres')),
            'librarySlug'   => $this->librarySlug,
            'lockedFields'  => $this->locked_fields,
        ];
    }
}
