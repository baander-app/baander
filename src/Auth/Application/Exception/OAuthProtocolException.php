<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/**
 * An OAuth 2.0 protocol error with its registered error code.
 *
 * The OAuth endpoints answer it as `{"error": ..., "error_description": ...}`
 * (RFC 6749 section 5.2, RFC 8628 section 3.5) with the given HTTP status.
 */
final class OAuthProtocolException extends RuntimeException
{
    /**
     * @param string|null $redirectUri Set once the authorization endpoint has validated the client's
     *                                 redirect URI; the error is then sent there (RFC 6749 section 4.1.2.1)
     */
    private function __construct(
        public readonly string $error,
        string $description,
        public readonly int $statusCode = 400,
        public readonly ?string $redirectUri = null,
    ) {
        parent::__construct($description);
    }

    /** The same error, delivered to the client's validated redirect URI. */
    public function redirectTo(string $redirectUri): self
    {
        return new self($this->error, $this->getMessage(), $this->statusCode, $redirectUri);
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description);
    }

    /** RFC 6749 section 5.2: failed client authentication answers 401. */
    public static function invalidClient(string $description = 'Client authentication failed.'): self
    {
        return new self('invalid_client', $description, 401);
    }

    public static function invalidGrant(string $description): self
    {
        return new self('invalid_grant', $description);
    }

    public static function unauthorizedClient(string $description): self
    {
        return new self('unauthorized_client', $description);
    }

    public static function unsupportedGrantType(string $grantType): self
    {
        return new self('unsupported_grant_type', sprintf('The grant type "%s" is not supported.', $grantType));
    }

    public static function unsupportedResponseType(): self
    {
        return new self('unsupported_response_type', 'Only the "code" response type is supported.');
    }

    public static function invalidScope(string $description): self
    {
        return new self('invalid_scope', $description);
    }

    public static function accessDenied(string $description): self
    {
        return new self('access_denied', $description);
    }

    public static function authorizationPending(): self
    {
        return new self('authorization_pending', 'The user has not yet approved the device.');
    }

    public static function slowDown(int $interval): self
    {
        return new self('slow_down', sprintf('Poll at most every %d seconds.', $interval));
    }

    public static function expiredToken(): self
    {
        return new self('expired_token', 'The device code has expired.');
    }

    public function getDescription(): string
    {
        return $this->getMessage();
    }
}
