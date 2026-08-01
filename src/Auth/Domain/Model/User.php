<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model;

use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class User
{
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
            totpSecret: null,
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

    public function changeEmail(string $newEmail): void
    {
        if ($newEmail === $this->state->email) {
            return;
        }

        $this->state->email = $newEmail;
        $this->state->emailVerifiedAt = null;
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

    public function getTotpSecret(): ?string
    {
        return $this->state->totpSecret;
    }

    public function setTotpSecret(?string $totp_secret): void
    {
        $this->state->totpSecret = $totp_secret;
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
            totpSecret: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            emailVerifiedAt: new DateTimeImmutable(),
            roles: $roles,
        ));
    }

    /**
     * Get the user's assigned roles.
     */
    public function getRoles(): array
    {
        return $this->state->roles;
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
