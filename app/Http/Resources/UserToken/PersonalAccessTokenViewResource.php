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
            /**
             * @var array<string>|null
             */
            'abilities'             => $this->abilities,
            'createdAt'             => $this->created_at,
            'deviceName'            => $this->device_name,
            'deviceOperatingSystem' => $this->device_operating_system,
            'expiresAt'             => $this->expires_at,
            'lastUsedAt'            => $this->last_used_at,
            'updatedAt'             => $this->updated_at,
            'userAgent'             => $this->user_agent,
            'ipAddress'             => $this->ip_address,
            /**
             * @var array<string>|null
             */
            'ipHistory'             => $this->ip_history,
            'countryCode'           => $this->country_code,
            'city'                  => $this->city,
            'ipChangeCount'         => $this->ip_change_count,
        ];
    }
}
