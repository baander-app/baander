<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for User aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class UserState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public string $name,
        public string $email,
        public string $password,
        public ?string $totpSecret,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $emailVerifiedAt = null,
        public array $roles = ['ROLE_USER'],
        public bool $disabled = false,
    ) {
    }
}
