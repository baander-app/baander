<?php

namespace App\Http\Resources\Image;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Image
 */
class ImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'path'      => $this->path,
            'extension' => $this->extension,
            'size'      => $this->size,
            'mime_type' => $this->mime_type,
            'width'     => $this->width,
            'height'    => $this->height,
            'url'       => route('api.image.serve', ['public_id' => $this->public_id]),
        ];
    }
}
