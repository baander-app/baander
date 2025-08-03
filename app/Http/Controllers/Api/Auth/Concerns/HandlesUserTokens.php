<?php

namespace App\Http\Controllers\Api\Auth\Concerns;

use App\Http\Resources\Auth\NewAccessTokenResource;
use App\Models\{PersonalAccessToken, TokenAbility, User};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

trait HandlesUserTokens
{
    private function createTokenSet(Request $request, User $user)
    {
        $device = PersonalAccessToken::prepareDeviceFromRequest($request);

        $accessToken = $user->createToken(
            name: 'access_token',
            abilities: [TokenAbility::ACCESS_API->value, TokenAbility::ACCESS_BROADCASTING->value],
            expiresAt: Carbon::now()->addMinutes(config('sanctum.access_token_expiration')),
            device: $device,
        );
        $refreshToken = $user->createToken(
            name: 'refresh_token',
            abilities: [TokenAbility::ISSUE_ACCESS_TOKEN->value],
            expiresAt: Carbon::now()->addMinutes(config('sanctum.refresh_token_expiration')),
            device: $device,
        );

        return response()->json([
            'accessToken'  => new NewAccessTokenResource($accessToken),
            'refreshToken' => new NewAccessTokenResource($refreshToken),
        ]);
    }
}
