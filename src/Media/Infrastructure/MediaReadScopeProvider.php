<?php

declare(strict_types=1);

namespace App\Media\Infrastructure;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\MediaReadScope;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class MediaReadScopeProvider implements MediaReadScopeProviderInterface
{
    public function __construct(
        private Security $security,
        private LibraryReadScopeProviderInterface $libraries,
    ) {
    }

    public function current(): MediaReadScope
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return MediaReadScope::none();
        }

        try {
            $actorId = Uuid::fromString($user->getId());
        } catch (\InvalidArgumentException) {
            return MediaReadScope::none();
        }

        return MediaReadScope::authenticated($actorId, $this->libraries->current());
    }
}
