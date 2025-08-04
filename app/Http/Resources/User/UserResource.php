<?php

namespace App\Http\Resources\User;

use App\Http\Resources\HasJsonCollection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
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
            'publicId'  => $this->public_id,
            'name'      => $this->name,
            'email'     => $this->email,
            'isAdmin'   => $this->isAdmin(),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
