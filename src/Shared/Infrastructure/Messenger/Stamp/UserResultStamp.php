<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Auth\Domain\Model\User;

final readonly class UserResultStamp implements ResultStampInterface
{
    public function __construct(
        private User $user,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof User ? new self($result) : null;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
