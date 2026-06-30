<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Query;

use App\Library\Application\Query\LibraryMembershipQueryPort;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class LibraryMembershipQuery implements LibraryMembershipQueryPort
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findUserIdsForLibrary(Uuid $libraryId): array
    {
        return $this->entityManager->getConnection()->executeQuery(
            'SELECT user_id FROM user_library_access WHERE library_id = :libraryId',
            ['libraryId' => $libraryId->toString()],
        )->fetchFirstColumn();
    }
}
