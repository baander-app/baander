<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Passkey;

/**
 * @param string $userId              User UUID string
 * @param string $name                Human-readable name for this passkey
 * @param string $credentialId        Base64url-encoded credential ID
 * @param array  $credentialRecordData Serialized CredentialRecord fields for persistence
 * @param int    $counter             Initial sign counter (from attestation response)
 */
final readonly class RegisterPasskeyCommand
{
    public function __construct(
        private string $userId,
        private string $name,
        private string $credentialId,
        private array $credentialRecordData,
        private int $counter,
    ) {
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

    public function getCredentialRecordData(): array
    {
        return $this->credentialRecordData;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }
}
