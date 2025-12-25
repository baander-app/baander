<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserToken\UserTokenIndexRequest;
use App\Models\Auth\OAuth\Token;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\Auth;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Prefix};

/**
 * User token management controller
 *
 * Handles OAuth token operations for users including listing active sessions,
 * viewing token details with security information, and revoking tokens for security management.
 */
#[Prefix('users/tokens')]
#[Middleware([
    'auth:oauth',
    'scope:access-api',
    'force.json',
])]
class UserTokenController extends Controller
{
    /**
     * Get a paginated collection of user's OAuth tokens
     *
     * Returns all active tokens for the authenticated user including detailed
     * security information such as device details, IP history, location data,
     * and usage statistics for session management.
     *
     * @param UserTokenIndexRequest $request Request with pagination and filtering parameters
     */
    #[Get('/{user}', 'api.user-tokens.index')]
    public function getUserTokens(UserTokenIndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currentTokenId = Auth::guard('oauth')->token()?->id;

        // Get paginated tokens with security and usage information
        $tokens = $user->tokens()
            ->with('metadata')
            ->where('revoked', false)
            ->orderBy('created_at', 'desc')
            ->paginate();

        // Enhance each token with additional security context
        $tokens->getCollection()->transform(function (Token $token) use ($currentTokenId) {
            return [
                'id' => $token->id,
                'token_id' => $token->token_id,
                'name' => $token->name,
                'scopes' => $token->scopes,
                'client_id' => $token->client_id,
                'is_current_session' => $token->id === $currentTokenId,
                'ip_address' => $token->metadata?->ip_address,
                'ip_change_count' => $token->metadata?->ip_change_count ?? 0,
                'country_code' => $token->metadata?->country_code,
                'city' => $token->metadata?->city,
                'ip_history' => $token->metadata?->ip_history ?? [],
                'user_agent' => $token->metadata?->user_agent,
                'device_name' => $token->metadata?->device_name,
                'device_operating_system' => $token->metadata?->device_operating_system,
                'created_at' => $token->created_at,
                'expires_at' => $token->expires_at,
            ];
        });

        return response()->json($tokens);
    }

    /**
     * Revoke a specific OAuth token
     *
     * Permanently revokes an OAuth token, ending the associated session.
     * Users can only revoke their own tokens. Includes security validation to
     * prevent unauthorized token revocation.
     *
     * @param Request $request Authenticated request
     * @param string $tokenId The token ID to revoke
     *
     * @throws AuthorizationException When user doesn't own the token
     * @status 204
     */
    #[Delete('/{token}', 'api.user-tokens.revoke')]
    public function revokeToken(Request $request, string $tokenId): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Token $token */
        $token = $user->tokens()->findOrFail($tokenId);

        // Security check: Ensure user owns the token being revoked
        if ($user->id !== $token->user_id) {
            abort(403, 'You can only revoke your own tokens.');
        }

        // Store token info for potential logging before deletion
        $tokenInfo = [
            'token_id' => $token->token_id,
            'token_name' => $token->name,
            'ip_address' => $token->metadata?->ip_address,
            'revoked_by_user' => $user->id,
            'revoked_at' => now(),
        ];

        // Revoke the token
        $token->revoke();

        // Log token revocation for security audit trail
        logger()->info('User token revoked', $tokenInfo);

        // Token successfully revoked - no content returned.
        return response(null, 204);
    }
}
