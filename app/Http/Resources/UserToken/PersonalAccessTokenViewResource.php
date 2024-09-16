<?php

namespace App\Http\Resources\UserToken;

use App\Http\Resources\HasJsonCollection;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PersonalAccessToken
 */
class PersonalAccessTokenViewResource extends JsonResource
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
            'id'                    => $this->id,
            'name'                  => $this->name,
            'abilities'             => $this->abilities,
            'userAgent'             => $this->user_agent,
            'clientName'            => $this->client_name,
            'clientVersion'         => $this->client_version,
            'clientType'            => $this->client_type,
            'deviceOperatingSystem' => $this->device_operating_system,
            'deviceName'            => $this->device_name,
            'lastUsedAt'            => $this->last_used_at,
            'expiresAt'             => $this->expires_at,
            'createdAt'             => $this->created_at,
            'updatedAt'             => $this->updated_at,
        ];
    }
}
