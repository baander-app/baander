<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Uuid;

final readonly class EmailVerificationTokenDTO
{
    public function __construct(
        public Uuid $id,
        public Uuid $userId,
        public string $token,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $usedAt = null,
    ) {
    }
}
