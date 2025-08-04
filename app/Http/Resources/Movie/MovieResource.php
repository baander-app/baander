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
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
            $this->mergeWhen(isset($this->videos_count), [
                'videoCount' => $this->videos_count,
            ]),
        ];
    }
}
