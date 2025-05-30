<?php

namespace App\Http\Resources\Song;

use App\Http\Resources\Album\AlbumWithoutSongsResource;
use App\Http\Resources\Artist\ArtistResource;
use App\Http\Resources\HasJsonCollection;
use App\Models\Song;
use App\Modules\Humanize\HumanDuration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use function App\Modules\Humanize\humanize_bytes;

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
        $streamUrl = $this->getStream();

        return [
            'public_id'     => $this->public_id,
            'title'         => $this->title,
            'year'          => $this->year,
            'comment'       => $this->comment,
            'disc'          => $this->disc,
            'length'        => $this->length,
            'durationHuman' => (new HumanDuration)->humanize($this->length),
            'lyrics'        => $this->lyrics,
            'lyricsExist'   => (bool)$this->lyrics,
            'modifiedTime'  => $this->modified_time,
            'path'          => $this->path,
            'track'         => $this->track,
            'size'          => $this->size,
            'sizeHuman'     => humanize_bytes($this->size),
            'mimeType'      => $this->mime_type,
            'hash'          => $this->hash,
            $this->mergeWhen($streamUrl, [
                'stream' => $streamUrl,
            ]),
            $this->mergeWhen($this->librarySlug, [
                'librarySlug' => $this->librarySlug,
            ]),
            'createdAt'     => $this->created_at,
            'updatedAt'     => $this->updated_at,
            'album'         => AlbumWithoutSongsResource::make($this->whenLoaded('album')),
            'artists'       => ArtistResource::collection($this->whenLoaded('artists')),
        ];
    }

    private function getStream()
    {
        $value = $this->when(isset($this->librarySlug), fn() => route('api.songs.stream', ['library' => $this->librarySlug, 'song' => $this->public_id]));

        if ($value) {
            return $value;
        }

        $value = $this->whenLoaded('album.library', fn() => route('api.songs.stream', ['library' => $this->album->library, 'song' => $this->public_id]));

        if ($value) {
            return $value;
        }

        return null;
    }
}
