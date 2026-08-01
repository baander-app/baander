<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Shared\Domain\Model\Email;
use InvalidArgumentException;

final readonly class RegisterUserDTO
{
    private Email $email;

    public function __construct(
        private string $name,
        string $email,
        private string $password,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name cannot be empty.');
        }

        $this->email = new Email($email);
        $this->validatePassword($password);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters long.');
        }
    }
}
