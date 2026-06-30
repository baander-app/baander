<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Auth\Domain\Model\User;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class UserResultStamp implements StampInterface
{
    public function __construct(
        private User $user,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return $result instanceof User ? new self($result) : null;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
