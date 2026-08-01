<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Entity;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_library_access')]
class UserLibraryAccessEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: UserEntity::class)]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private UserEntity $user,

        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: LibraryEntity::class)]
        #[ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private LibraryEntity $library,

        #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'now()'])]
        private \DateTimeImmutable $grantedAt,
    ) {
    }

    public function getUserId(): Uuid
    {
        return $this->user->getId();
    }

    public function getLibraryId(): Uuid
    {
        return $this->library->getId();
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }
}
