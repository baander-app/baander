<?php

declare(strict_types=1);

namespace App\Library\Infrastructure;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class LibraryReadScopeProvider implements LibraryReadScopeProviderInterface
{
    public function __construct(private Security $security, private LibraryAccessPortInterface $access)
    {
    }

    public function current(): LibraryReadScope
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return LibraryReadScope::none();
        }
        try {
            $userId = Uuid::fromString($user->getId());
        } catch (\InvalidArgumentException) {
            return LibraryReadScope::none();
        }
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return LibraryReadScope::unrestricted();
        }
        return LibraryReadScope::restricted(array_map(Uuid::fromString(...), $this->access->getUserLibraryIds($userId)));
    }
}
