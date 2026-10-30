<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class CurrentUserAuthority
{
    /** @param list<string> $roles */
    public function __construct(public array $roles, public bool $disabled) {}

    public function isAdministrator(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles, true) || in_array('ROLE_SUPER_ADMIN', $this->roles, true);
    }
}
