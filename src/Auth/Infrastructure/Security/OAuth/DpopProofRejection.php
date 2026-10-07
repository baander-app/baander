<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

/**
 * Why a token request's DPoP proof (RFC 9449) was not accepted.
 *
 * The OAuth token endpoint answers `use_dpop_nonce` when the proof lacks a
 * fresh server nonce and `invalid_dpop_proof` when the proof is missing or
 * invalid (RFC 9449 sections 5 and 8). First-party login keeps its own answers.
 */
final readonly class DpopProofRejection
{
    public const string MISSING = 'missing';
    public const string NONCE_REQUIRED = 'nonce_required';
    public const string INVALID = 'invalid';

    /** @param self::MISSING|self::NONCE_REQUIRED|self::INVALID $reason */
    private function __construct(
        public string $reason,
        public string $description,
    ) {
    }

    public static function missing(): self
    {
        return new self(self::MISSING, 'DPoP proof header is required.');
    }

    public static function nonceRequired(string $description): self
    {
        return new self(self::NONCE_REQUIRED, $description);
    }

    public static function invalid(string $description): self
    {
        return new self(self::INVALID, $description);
    }

    /** The RFC 9449 error code for this rejection. */
    public function oauthError(): string
    {
        return $this->reason === self::NONCE_REQUIRED ? 'use_dpop_nonce' : 'invalid_dpop_proof';
    }
}
