<?php

namespace App\Http\Resources\Playlist;

use App\Http\Resources\Image\ImageResource;
use App\Http\Resources\Song\SongResource;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Playlist
 */
class PlaylistResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'publicId'        => $this->public_id,
            'name'            => $this->name,
            'description'     => $this->description,
            'isPublic'        => $this->is_public,
            'isCollaborative' => $this->is_collaborative,
            'isSmart'         => $this->is_smart,
            'smartRules'      => $this->when($this->is_smart, $this->smart_rules),
            'cover'           => ImageResource::make($this->whenLoaded('cover')),
            'createdAt'       => $this->created_at,
            'updatedAt'       => $this->updated_at,
            'songsCount'      => $this->whenCounted('songs'),
            'statistics'      => $this->whenLoaded('statistics', [
                'views'     => $this->statistics?->views ?? 0,
                'plays'     => $this->statistics?->plays ?? 0,
                'shares'    => $this->statistics?->shares ?? 0,
                'favorites' => $this->statistics?->favorites ?? 0,
            ]),
            'songs'           => SongResource::collection($this->whenLoaded('songs')),
            'owner'           => $this->whenLoaded('user', [
                'email' => $this->user->email,
                'name'  => $this->user->name,
            ]),
            'collaborators'   => $this->whenLoaded('collaborators', function () {
                return $this->collaborators->map(function ($collaborator) {
                    return [
                        'id'   => $collaborator->email,
                        'name' => $collaborator->name,
                        'role' => $collaborator->pivot->role,
                    ];
                });
            }),
        ];
    }
}
