<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

final readonly class PasswordHashUpgrade
{
    public function __construct(
        #[\SensitiveParameter]
        public string $verifiedHash,
        #[\SensitiveParameter]
        public string $replacementHash,
    ) {
    }
}
