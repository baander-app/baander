<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Auth\Application\Port\UserIdentifierResolverInterface;
use App\Library\Application\Command\GrantLibraryAccessCommand;
use App\Library\Application\DTO\LibraryAccess;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Service\LibraryLookup;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Lets a user see a library, for the admin user page and `app:library:member:grant`. Granting twice changes nothing. */
final readonly class GrantLibraryAccessHandler
{
    public function __construct(
        private UserIdentifierResolverInterface $users,
        private LibraryLookup $lookup,
        private LibraryAccessPortInterface $access,
    ) {
    }

    /**
     * @throws NotFoundException        when no user has the UUID or email address
     * @throws LibraryNotFoundException when no library has the UUID or slug
     */
    #[AsMessageHandler]
    public function __invoke(GrantLibraryAccessCommand $command): LibraryAccess
    {
        $userId = $this->users->userId($command->user);
        $library = $this->lookup->byIdentifier($command->library);

        $this->access->grant($userId, $library->getId());

        return new LibraryAccess($library, granted: true);
    }
}
