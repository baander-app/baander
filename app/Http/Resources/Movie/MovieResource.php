<?php

namespace App\Http\Resources\Movie;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Movie
 */
class MovieResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title'      => $this->title,
            'slug'       => $this->slug,
            'year'       => $this->year,
            'summary'    => $this->summary,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            $this->mergeWhen(isset($this->videos_count), [
                'videos_count' => $this->videos_count,
            ]),
        ];
    }
}
