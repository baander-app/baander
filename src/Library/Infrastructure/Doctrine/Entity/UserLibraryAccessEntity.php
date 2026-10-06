<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_library_access')]
#[ORM\Index(name: 'idx_user_library_access_library_id', columns: ['library_id'])]
class UserLibraryAccessEntity
{
    /**
     * The user is an Auth-owned row referenced by ID; LibraryForeignKeys keeps its
     * migration-defined cascade visible to schema comparison.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'uuid')]
        private Uuid $userId,

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
        return $this->userId;
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
