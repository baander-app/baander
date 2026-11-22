<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository;

use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface UserRepositoryInterface
{
    /**
     * Persists the user. When the email address or password hash differs from the stored
     * one, the user's outstanding password reset token is removed: it was issued for the
     * old credentials. When the email address differs, the outstanding email verification
     * token is removed too. The user's deletion removes both.
     */
    public function save(User $user): void;

    public function delete(Uuid $id): void;

    public function findByEmail(Email $email): ?User;

    public function findByPublicId(PublicId $publicId): ?User;

    public function findByUuid(Uuid $uuid): ?User;

    public function existsWithEmail(Email $email): bool;

    /**
     * @return list<User>
     */
    public function findAll(?string $roleFilter = null, ?bool $disabledFilter = null, int $limit = 50, int $offset = 0): array;

    public function count(?string $roleFilter = null, ?bool $disabledFilter = null): int;

    /**
     * Takes the lock that serializes deleting, disabling and demoting users, so a change that
     * can take an enabled super admin away waits for another one to commit or roll back.
     * Requires an open transaction; the lock holds until it ends.
     *
     * @throws \LogicException outside a transaction
     */
    public function lockSuperAdminRemovals(): void;

    /**
     * The ID of the install's only enabled super admin, or null when it has none or several.
     * Read after lockSuperAdminRemovals() in the same transaction, it reflects every removal
     * that committed while the lock waited.
     */
    public function soleEnabledSuperAdmin(): ?Uuid;

    /**
     * Upgrades the hash if it still matches the one verified at login. Call under the
     * credential epoch lock. A hash upgrade preserves the credentials and reset links.
     */
    public function upgradePasswordHash(Uuid $id, string $verifiedHash, string $replacementHash): void;

    /**
     * Spends a TOTP time step: records it as the user's last used step when it is later than
     * the recorded one, in one conditional statement. Returns false when that step or a later
     * one was already spent, so each code is accepted once, also by concurrent requests.
     */
    public function spendTotpStep(Uuid $id, int $step): bool;

    /**
     * Forgets the user's last spent TOTP time step, for an operator's TOTP reset: a secret
     * enrolled afterwards starts with no step spent.
     */
    public function clearTotpStep(Uuid $id): void;

    /**
     * Locks the user's row FOR NO KEY UPDATE and returns the credential epoch as of the lock,
     * so a password change that committed while the lock waited is seen. Null when the user does not exist. Requires an open transaction; the
     * lock holds until it ends.
     */
    public function lockCredentialEpoch(Uuid $id): ?int;

    /**
     * Increments the user's credential epoch in one statement and returns the new value. The
     * row stays locked until the transaction ends, so issuance waiting on it sees the change.
     */
    public function advanceCredentialEpoch(Uuid $id): int;
}
