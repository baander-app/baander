<?php

declare(strict_types=1);

namespace App\Library\Application\QueryHandler;

use App\Auth\Application\Port\UserIdentifierResolverInterface;
use App\Library\Application\DTO\LibraryAccess;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Query\ListLibraryAccessQuery;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Every library with whether a user may see it, for the admin user page and `app:library:member:list`. */
final readonly class ListLibraryAccessHandler
{
    public function __construct(
        private UserIdentifierResolverInterface $users,
        private LibraryRepositoryInterface $libraries,
        private LibraryAccessPortInterface $access,
    ) {
    }

    /**
     * @return list<LibraryAccess> in display order
     *
     * @throws NotFoundException when no user has the UUID or email address
     */
    #[AsMessageHandler]
    public function __invoke(ListLibraryAccessQuery $query): array
    {
        $granted = array_flip($this->access->getUserLibraryIds($this->users->userId($query->user)));

        return array_values(array_map(
            static fn (Library $library): LibraryAccess => new LibraryAccess($library, isset($granted[$library->getId()->toString()])),
            $this->libraries->findVisible(LibraryReadScope::unrestricted()),
        ));
    }
}
