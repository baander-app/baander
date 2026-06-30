<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_library_access')]
class UserLibraryAccessEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $userId,

        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $libraryId,

        #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'now()'])]
        private \DateTimeImmutable $grantedAt,
    ) {
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getLibraryId(): Uuid
    {
        return $this->libraryId;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }
}
