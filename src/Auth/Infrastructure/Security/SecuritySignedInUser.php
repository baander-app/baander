<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Application\Port\SignedInUserInterface;
use Symfony\Bundle\SecurityBundle\Security;

/** Reads the signed-in user from the security token of the current request. */
final readonly class SecuritySignedInUser implements SignedInUserInterface
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function id(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof AuthenticatedUserIdentityInterface ? $user->getId() : null;
    }
}
