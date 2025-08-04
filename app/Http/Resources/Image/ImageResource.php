<?php

namespace App\Http\Resources\Image;

use App\Http\Resources\HasJsonCollection;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Image
 */
class ImageResource extends JsonResource
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
            'publicId' => $this->public_id,
            'path'      => $this->path,
            'extension' => $this->extension,
            'size'      => $this->size,
            'mimeType' => $this->mime_type,
            'width'     => $this->width,
            'height'    => $this->height,
            'blurash'  => $this->blurhash,
            'url'       => route('api.image.serve', ['image' => $this->public_id]),
        ];
    }
}
