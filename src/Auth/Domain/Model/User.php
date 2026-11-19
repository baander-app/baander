<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model;

use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class User
{
    /** The roles a user can be assigned. */
    public const array ROLES = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    private const ROLE_HIERARCHY = [
        'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN', 'ROLE_USER'],
        'ROLE_ADMIN' => ['ROLE_USER'],
    ];

    private function __construct(
        private UserState $state,
    ) {
    }

    /**
     * Create a new user via registration.
     */
    public static function register(Email $email, string $hashedPassword, string $name): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name cannot be empty.');
        }

        return new self(new UserState(
            id: new Uuid(),
            publicId: new \App\Shared\Domain\Model\PublicId(),
            name: $name,
            email: $email->toString(),
            password: $hashedPassword,
            totpSecretCiphertext: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            roles: ['ROLE_USER'],
        ));
    }

    /**
     * Reconstitute a User from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(UserState $state): self
    {
        return new self($state);
    }

    public function verifyEmail(): void
    {
        if ($this->isEmailVerified()) {
            return;
        }

        $this->state->emailVerifiedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function changePassword(string $newHashedPassword): void
    {
        if ($newHashedPassword === $this->state->password) {
            return;
        }

        $this->state->password = $newHashedPassword;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Holds the address the user asked to change to. The account keeps its address, and that
     * address's verification, until confirmPendingEmail(); asking for the current address drops
     * the pending change.
     */
    public function requestEmailChange(Email $address): void
    {
        $pending = $address->equals(Email::fromString($this->state->email)) ? null : $address->toString();
        if ($pending === $this->state->pendingEmail) {
            return;
        }

        $this->state->pendingEmail = $pending;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Makes the pending address the account's verified address; its owner has just used the
     * link sent to it. The caller checks that no other account uses it.
     *
     * @throws \LogicException when no change is pending
     */
    public function confirmPendingEmail(): void
    {
        $pending = $this->state->pendingEmail ?? throw new \LogicException('No email change is pending.');

        $now = new DateTimeImmutable();
        $this->state->email = $pending;
        $this->state->emailVerifiedAt = $now;
        $this->state->pendingEmail = null;
        $this->state->updatedAt = $now;
    }

    public function discardPendingEmail(): void
    {
        if ($this->state->pendingEmail === null) {
            return;
        }

        $this->state->pendingEmail = null;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * An operator's email change: it takes effect at once, counts as verified and replaces any
     * change the user had pending.
     */
    public function assignVerifiedEmail(Email $address): void
    {
        if ($address->equals(Email::fromString($this->state->email))) {
            return;
        }

        $now = new DateTimeImmutable();
        $this->state->email = $address->toString();
        $this->state->emailVerifiedAt = $now;
        $this->state->pendingEmail = null;
        $this->state->updatedAt = $now;
    }

    /**
     * A redeemed password reset proves control of the current address. On an address that was
     * never verified, the account may have been registered by someone else under it, so the
     * reset verifies the address, turns TOTP off and drops a pending email change; the caller
     * removes the account's passkeys and sessions in the same transaction.
     */
    public function claimByPasswordReset(): void
    {
        $this->verifyEmail();
        $this->state->totpSecretCiphertext = null;
        $this->state->pendingEmail = null;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name cannot be empty.');
        }

        $this->state->name = $name;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): \App\Shared\Domain\Model\PublicId
    {
        return $this->state->publicId;
    }

    public function getName(): string
    {
        return $this->state->name;
    }

    public function getEmail(): string
    {
        return $this->state->email;
    }

    public function getPassword(): string
    {
        return $this->state->password;
    }

    public function getPendingEmail(): ?string
    {
        return $this->state->pendingEmail;
    }

    /**
     * The credential epoch as it was when this user was loaded. A login captures it from the
     * read that checked its credential; issuance compares it with the epoch under the row lock.
     */
    public function getCredentialEpoch(): int
    {
        return $this->state->credentialEpoch;
    }

    public function isEmailVerified(): bool
    {
        return $this->state->emailVerifiedAt !== null;
    }

    public function getEmailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->state->emailVerifiedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    /** TOTP is on exactly when a sealed secret is stored, whether or not it can be opened. */
    public function hasTotp(): bool
    {
        return $this->state->totpSecretCiphertext !== null;
    }

    /** The sealed TOTP secret; only the application's TOTP cipher can open it. */
    public function getTotpSecretCiphertext(): ?string
    {
        return $this->state->totpSecretCiphertext;
    }

    public function enableTotp(string $secretCiphertext): void
    {
        $this->state->totpSecretCiphertext = $secretCiphertext;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function disableTotp(): void
    {
        $this->state->totpSecretCiphertext = null;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function disable(): void
    {
        $this->state->disabled = true;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function enable(): void
    {
        $this->state->disabled = false;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function isDisabled(): bool
    {
        return $this->state->disabled;
    }

    /**
     * Create a new user via operator action (CLI command).
     *
     * Unlike register(), this pre-verifies the email and accepts roles.
     */
    /** @param array<array-key, string> $roles */
    public static function createByOperator(Email $email, string $hashedPassword, string $name, array $roles): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name cannot be empty.');
        }

        return new self(new UserState(
            id: new Uuid(),
            publicId: new \App\Shared\Domain\Model\PublicId(),
            name: $name,
            email: $email->toString(),
            password: $hashedPassword,
            totpSecretCiphertext: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            emailVerifiedAt: new DateTimeImmutable(),
            roles: $roles,
        ));
    }

    /**
     * Get the user's assigned roles.
     */
    /** @return array<array-key, string> */
    public function getRoles(): array
    {
        return $this->state->roles;
    }

    /**
     * Replaces the assigned roles with the given ones. A repeated role counts once, and
     * the roles the user already has, in any order, change nothing.
     *
     * @param array<array-key, string> $roles
     *
     * @throws InvalidArgumentException when no role is given or a role is not one of ROLES
     */
    public function replaceRoles(array $roles): void
    {
        $roles = array_values(array_unique($roles));
        if ($roles === []) {
            throw new InvalidArgumentException('A user needs at least one role.');
        }

        foreach ($roles as $role) {
            if (!in_array($role, self::ROLES, true)) {
                throw new InvalidArgumentException(sprintf('Unknown role "%s". Allowed roles: %s.', $role, implode(', ', self::ROLES)));
            }
        }

        $current = $this->state->roles;
        $requested = $roles;
        sort($current);
        sort($requested);
        if ($current === $requested) {
            return;
        }

        $this->state->roles = $roles;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Check if the user has a given role, resolving the role hierarchy.
     *
     * An admin user (ROLE_ADMIN) will return true for ROLE_USER as well,
     * matching the hierarchy defined in security.yaml.
     */
    public function hasRole(string $role): bool
    {
        if (in_array($role, $this->state->roles, true)) {
            return true;
        }

        // Check if any of the user's roles inherit the requested role
        foreach ($this->state->roles as $assignedRole) {
            $inherited = self::ROLE_HIERARCHY[$assignedRole] ?? [];
            if (in_array($role, $inherited, true)) {
                return true;
            }
        }

        return false;
    }

    public function getState(): UserState
    {
        return $this->state;
    }
}
