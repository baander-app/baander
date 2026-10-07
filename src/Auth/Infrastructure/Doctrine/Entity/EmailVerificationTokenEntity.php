<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A user's single outstanding email verification token. Only the SHA-256 hash of the token
 * is stored, with the address it verifies; the row is deleted with the user
 * (fk_email_verification_tokens_user_id).
 *
 * This mapping keeps schema comparison aware of the table. EmailVerificationTokenRepository
 * writes it with single SQL statements and never loads the entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_verification_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_email_verification_tokens_token_hash', columns: ['token_hash'])]
class EmailVerificationTokenEntity
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserEntity $user;

    #[ORM\Column(type: 'citext')]
    private string $email;

    #[ORM\Column(type: 'text')]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(UserEntity $user, string $email, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->email = $email;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getUser(): UserEntity
    {
        return $this->user;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
