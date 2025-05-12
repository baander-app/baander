<?php

namespace App\Http\Resources\PlaylistStatistic;

use App\Http\Resources\Playlist\PlaylistResource;
use App\Models\PlaylistStatistic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlaylistStatistic
 */
class PlaylistStatisticResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'views'     => $this->views,
            'plays'     => $this->plays,
            'shares'    => $this->shares,
            'favorites' => $this->favorites,
            'playlist' => PlaylistResource::make($this->whenLoaded('playlist')),
        ];
    }
}
