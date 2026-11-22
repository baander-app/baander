<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Passkey;

/**
 * @param string $userId              User UUID string
 * @param string $name                Human-readable name for this passkey
 * @param string $credentialId        Base64url-encoded credential ID
 * @param array<array-key, mixed> $credentialRecordData Serialized CredentialRecord fields for persistence
 * @param int    $counter             Initial sign counter (from attestation response)
 * @param string|null $currentAccessTokenId The access token the request was made with; its session stays signed in
 */
final readonly class RegisterPasskeyCommand
{
    /** @param array<array-key, mixed> $credentialRecordData */
    public function __construct(
        private string $userId,
        private string $name,
        private string $credentialId,
        private array $credentialRecordData,
        private int $counter,
        private ?string $currentAccessTokenId = null,
    ) {
    }

    public function getCurrentAccessTokenId(): ?string
    {
        return $this->currentAccessTokenId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    /** @return array<array-key, mixed> */
    public function getCredentialRecordData(): array
    {
        return $this->credentialRecordData;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }
}
