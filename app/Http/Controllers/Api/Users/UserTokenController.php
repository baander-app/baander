<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserToken\UserTokenIndexRequest;
use App\Http\Resources\UserToken\PersonalAccessTokenViewResource;
use Illuminate\Http\Response;
use App\Models\{PersonalAccessToken, TokenAbility};
use App\Modules\Http\Pagination\JsonPaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Prefix};

#[Prefix('users/tokens')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class UserTokenController extends Controller
{

    /**
     * Get a collection of tokens
     *
     * @param UserTokenIndexRequest $request The HTTP request instance.
     *
     * @return AnonymousResourceCollection<JsonPaginator<PersonalAccessTokenViewResource>> The collection of personal access tokens.
     */
    #[Get('/{user}')]
    public function getUserTokens(UserTokenIndexRequest $request)
    {
        $tokens = $request->user()->tokens()->paginate();

        return PersonalAccessTokenViewResource::collection($tokens);
    }


    /**
     * Revoke a given token
     *
     * @param Request $request
     * @param PersonalAccessToken $token
     * @return Response
     */
    #[Delete('/{token}')]
    public function revokeToken(Request $request, PersonalAccessToken $token)
    {
        if ($request->user()->id !== $token->tokenable_id) {
            abort(401);
        }

        $token->delete();

        return response(null, 204);
    }
}
