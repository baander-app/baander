<?php

namespace App\Http\Resources\Auth;

use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PersonalAccessToken
 */
class PersonalAccessTokenResource extends JsonResource
{
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
            'clientName'            => $this->client_name,
            'clientVersion'         => $this->client_version,
            'clientType'            => $this->client_type,
            'deviceOperatingSystem' => $this->device_operating_system,
            'deviceName'            => $this->device_name,
            'deviceBrandName'       => $this->device_brand_name,
            'deviceModel'           => $this->device_model,
            'deviceType'            => $this->device_type,
            'expiresAt'             => $this->expires_at,
            'createdAt'             => $this->created_at,
            'updatedAt'             => $this->updated_at,
        ];
    }
}
