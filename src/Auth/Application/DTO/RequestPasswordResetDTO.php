<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Email;

final readonly class RequestPasswordResetDTO
{
    public function __construct(
        private Email $email,
    ) {
    }

    public function getEmail(): Email
    {
        return $this->email;
    }
}
