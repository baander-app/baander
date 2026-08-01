<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

use App\Shared\Domain\Model\Email;

final readonly class CreateUserCommand
{
    /**
     * @param string[] $roles
     */
    public function __construct(
        private Email $email,
        private string $name,
        private string $plainPassword,
        private array $roles,
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

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        return $this->roles;
    }
}
