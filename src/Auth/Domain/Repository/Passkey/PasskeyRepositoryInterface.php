<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\Passkey;

use App\Auth\Domain\Model\Passkey\Passkey;
use App\Shared\Domain\Model\Uuid;

interface PasskeyRepositoryInterface
{
    /**
     * Persist a passkey for a given user.
     */
    public function save(Passkey $passkey, Uuid $userId): void;

    /**
     * Remove a passkey from persistence.
     */
    public function remove(Passkey $passkey): void;

    /**
     * Find a passkey by its credential ID.
     */
    public function ofCredentialId(string $credentialId): ?Passkey;

    /**
     * Find a passkey by its ID.
     */
    public function ofId(Uuid $id): ?Passkey;

    /**
     * Get all passkeys for a given user.
     *
     * @return list<Passkey>
     */
    public function forUser(Uuid $userId): array;

    /**
     * Resolve the user ID for a given credential ID.
     */
    public function userIdForCredentialId(string $credentialId): ?Uuid;

    /**
     * Mark a passkey as used (persists the updated timestamp).
     */
    public function markUsed(Passkey $passkey): void;
}
