<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Auth\Application\DTO\PasswordHashUpgrade;
use App\Shared\Domain\Model\Uuid;

/**
 * Issues a first-party token pair to a user that a login authenticator verified.
 *
 * Password and passkey login are the only callers. The pair is bound to the
 * DPoP key that signed the login request.
 */
final readonly class IssueTokenCommand
{
    /**
     * @param string[] $scopes
     * @param string $dpopJkt Thumbprint of the DPoP key the tokens are bound to (RFC 9449)
     * @param int $credentialEpoch The user's credential epoch read with the credential the login verified;
     *                             issuance refuses when a password change moved it since
     * @param string|null $clientFingerprint Device fingerprint the access token is bound to, when the client sent one
     * @param int|null $totpStep TOTP time step the login's code matched; spent in the transaction that stores the tokens
     */
    public function __construct(
        private Uuid $clientId,
        private Uuid $userId,
        private string $dpopJkt,
        private int $credentialEpoch,
        private array $scopes = [],
        private ?string $tokenName = null,
        private ?string $ipAddress = null,
        private ?string $userAgent = null,
        private ?string $clientFingerprint = null,
        private ?int $totpStep = null,
        private ?PasswordHashUpgrade $passwordHashUpgrade = null,
    ) {
    }

    public function getClientId(): Uuid
    {
        return $this->clientId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getDpopJkt(): string
    {
        return $this->dpopJkt;
    }

    public function getCredentialEpoch(): int
    {
        return $this->credentialEpoch;
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getTokenName(): ?string
    {
        return $this->tokenName;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getClientFingerprint(): ?string
    {
        return $this->clientFingerprint;
    }

    public function getTotpStep(): ?int
    {
        return $this->totpStep;
    }

    public function getPasswordHashUpgrade(): ?PasswordHashUpgrade
    {
        return $this->passwordHashUpgrade;
    }
}
