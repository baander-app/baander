<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class LoginBlockState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly string $ipAddress,
        public readonly string $email,
        public readonly string $fieldValue,
        public readonly string $userAgent,
        public readonly DateTimeImmutable $createdAt,
    ) {}
}
