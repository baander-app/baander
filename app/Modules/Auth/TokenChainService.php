<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Models\Auth\OAuth\RefreshToken;
use App\Models\Auth\OAuth\Token;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Service for managing OAuth token chains and refresh token rotation
 *
 * Implements security best practices:
 * - Refresh token rotation (single use)
 * - Token chaining for audit trail
 * - Automatic chain revocation on token reuse detection
 */
class TokenChainService
{
    /**
     * Generate a new chain ID for fresh token sets
     */
    public function generateChainId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Link tokens together in a chain when creating new tokens
     *
     * @param Token $accessToken The newly created access token
     * @param RefreshToken $refreshToken The newly created refresh token
     * @param string|null $previousRefreshTokenId The previous refresh token ID (if refreshing)
     */
    public function linkTokens(
        Token $accessToken,
        RefreshToken $refreshToken,
        ?string $previousRefreshTokenId = null,
    ): void {
        $chainId = $this->getOrCreateChainId($previousRefreshTokenId);

        DB::beginTransaction();
        try {
            // Link access token to chain
            $accessToken->update(['chain_id' => $chainId]);

            // Link refresh token to chain and previous token
            $refreshToken->update([
                'chain_id' => $chainId,
                'previous_refresh_token_id' => $previousRefreshTokenId,
            ]);

            // Mark previous refresh token as used
            if ($previousRefreshTokenId) {
                RefreshToken::where('id', $previousRefreshTokenId)->update([
                    'used_at' => now(),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to link tokens in chain', [
                'error' => $e->getMessage(),
                'access_token_id' => $accessToken->id,
                'refresh_token_id' => $refreshToken->id,
            ]);
            throw new RuntimeException('Failed to link tokens in chain', 0, $e);
        }
    }

    /**
     * Validate refresh token and detect reuse
     *
     * @param RefreshToken $refreshToken The refresh token to validate
     * @throws RuntimeException If token reuse is detected
     */
    public function validateRefreshToken(RefreshToken $refreshToken): void
    {
        if ($refreshToken->wasUsed()) {
            // Token reuse detected - revoke entire chain
            Log::warning('Refresh token reuse detected - revoking entire chain', [
                'refresh_token_id' => $refreshToken->id,
                'chain_id' => $refreshToken->chain_id,
                'used_at' => $refreshToken->used_at,
            ]);

            if ($refreshToken->chain_id) {
                $this->revokeChain($refreshToken->chain_id);
            }

            throw new RuntimeException('Refresh token has already been used. For security, all tokens in this chain have been revoked.');
        }

        if ($refreshToken->isRevoked()) {
            throw new RuntimeException('Refresh token has been revoked.');
        }
    }

    /**
     * Revoke all tokens in a chain (security measure)
     */
    public function revokeChain(string $chainId): void
    {
        Token::where('chain_id', $chainId)->update(['revoked' => true]);
        RefreshToken::where('chain_id', $chainId)->update(['revoked' => true]);

        Log::info('Token chain revoked', ['chain_id' => $chainId]);
    }

    /**
     * Get or create a chain ID
     *
     * If a previous refresh token exists, use its chain ID.
     * Otherwise, generate a new chain ID.
     */
    private function getOrCreateChainId(?string $previousRefreshTokenId): string
    {
        if ($previousRefreshTokenId) {
            $previousToken = RefreshToken::find($previousRefreshTokenId);
            if ($previousToken && $previousToken->chain_id) {
                return $previousToken->chain_id;
            }
        }

        return $this->generateChainId();
    }
}
