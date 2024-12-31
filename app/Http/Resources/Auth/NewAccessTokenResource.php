<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\NewAccessToken;

/**
 * @mixin NewAccessToken
 */
class NewAccessTokenResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token'     => Crypt::encryptString($this->plainTextToken),
            'abilities' => $this->accessToken->abilities,
            'expiresAt' => $this->accessToken->expires_at,
        ];
    }
}
