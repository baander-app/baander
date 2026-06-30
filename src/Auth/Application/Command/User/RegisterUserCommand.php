<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

use App\Shared\Domain\Model\Email;

final readonly class RegisterUserCommand
{
    public function __construct(
        private Email $email,
        private string $name,
        private string $plainPassword,
    ) {
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPlainPassword(): string
    {
        return $this->plainPassword;
    }
}
