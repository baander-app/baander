<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Auth\Domain\Model\User;

/** A page of users and the number of users matching the filters across all pages. */
final readonly class UserPage
{
    /**
     * @param list<User> $users
     */
    public function __construct(
        public array $users,
        public int $total,
    ) {
    }
}
