<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserToken\UserTokenIndexRequest;
use App\Http\Resources\UserToken\PersonalAccessTokenViewResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\{PersonalAccessToken, TokenAbility, User};
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Prefix};

/**
 * User token management controller
 *
 * Handles personal access token operations for users including listing active sessions,
 * viewing token details with security information, and revoking tokens for security management.
 */
#[Prefix('users/tokens')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class UserTokenController extends Controller
{
    /**
     * Get a paginated collection of user's personal access tokens
     *
     * Returns all active tokens for the authenticated user including detailed
     * security information such as device details, IP history, location data,
     * and usage statistics for session management.
     *
     * @param UserTokenIndexRequest $request Request with pagination and filtering parameters
     *
     * @response AnonymousResourceCollection<JsonPaginator<PersonalAccessTokenViewResource>>
     */
    #[Get('/{user}', 'api.user-tokens.index')]
    public function getUserTokens(UserTokenIndexRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        // Get paginated tokens with security and usage information
        $tokens = $user->tokens()
            ->orderBy('last_used_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate();

        // Enhance each token with additional security context
        $tokens->getCollection()->each(function (PersonalAccessToken $token) use ($request) {
            // Mark if this is the current session token
            $token->is_current_session = $token->id === $request->user()->currentAccessToken()?->id;

            // Parse IP history for security monitoring
            if ($token->ip_history) {
                $token->parsed_ip_history = json_decode($token->ip_history, true);
            }
        });

        return PersonalAccessTokenViewResource::collection($tokens);
    }

    /**
     * Revoke a specific personal access token
     *
     * Permanently revokes a personal access token, ending the associated session.
     * Users can only revoke their own tokens. Includes security validation to
     * prevent unauthorized token revocation.
     *
     * @param Request $request Authenticated request
     * @param PersonalAccessToken $token The token to revoke
     *
     * @throws AuthorizationException When user doesn't own the token
     * @throws ModelNotFoundException When token is not found
     * @status 204
     */
    #[Delete('/{token}', 'api.user-tokens.revoke')]
    public function revokeToken(Request $request, PersonalAccessToken $token): Response
    {
        /** @var User $user */
        $user = $request->user();

        // Security check: Ensure user owns the token being revoked
        if ($user->id !== $token->tokenable_id) {
            abort(403, 'You can only revoke your own tokens.');
        }

        // Additional security check: Verify token belongs to a User model
        if ($token->tokenable_type !== User::class) {
            abort(403, 'Invalid token type.');
        }

        // Store token info for potential logging before deletion
        $tokenInfo = [
            'token_id'        => $token->id,
            'token_name'      => $token->name,
            'ip_address'      => $token->ip_address,
            'revoked_by_user' => $user->id,
            'revoked_at'      => now(),
        ];

        // Revoke the token
        $token->delete();

        // Log token revocation for security audit trail
        logger()->info('User token revoked', $tokenInfo);

        // Token successfully revoked - no content returned.
        return response(null, 204);
    }
}
