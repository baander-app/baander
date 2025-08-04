<?php

namespace App\Http\Resources\Auth;

use App\Http\Resources\HasJsonCollection;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PersonalAccessToken
 */
class PersonalAccessTokenResource extends JsonResource
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
            'name'                  => $this->name,
            'abilities'             => $this->abilities,
            'userAgent'             => $this->user_agent,
            'deviceOperatingSystem' => $this->device_operating_system,
            'deviceName'            => $this->device_name,
            'expiresAt'             => $this->expires_at,
            'createdAt'             => $this->created_at,
            'updatedAt'             => $this->updated_at,
        ];
    }
}
