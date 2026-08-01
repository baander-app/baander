<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Email;

final readonly class LoginUserDTO
{
    public function __construct(
        private Email $email,
        private string $password,
    ) {
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }
}
