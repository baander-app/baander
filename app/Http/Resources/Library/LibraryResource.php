<?php

namespace App\Http\Resources\Library;

use App\Http\Resources\HasJsonCollection;
use App\Models\Library;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Library
 */
class LibraryResource extends JsonResource
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
            'name'      => $this->name,
            'slug'      => $this->slug,
            'path'      => $this->path,
            'type'      => $this->type,
            'order'     => $this->order,
            'lastScan'  => $this->last_scan,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
