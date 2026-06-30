<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

use App\Shared\Domain\Model\Email;

final readonly class RequestPasswordResetCommand
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
