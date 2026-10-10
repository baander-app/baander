<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Auth\Application\Port\UserIdentifierResolverInterface;
use App\Library\Application\Command\RevokeLibraryAccessCommand;
use App\Library\Application\DTO\LibraryAccess;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Service\LibraryLookup;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stops a user seeing a library, for the admin user page and `app:library:member:revoke`.
 * Revoking access the user lacks changes nothing. The user's next request is refused; signed
 * media URLs already issued stay valid until they expire.
 */
final readonly class RevokeLibraryAccessHandler
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
    public function __invoke(RevokeLibraryAccessCommand $command): LibraryAccess
    {
        $userId = $this->users->userId($command->user);
        $library = $this->lookup->byIdentifier($command->library);

        $this->access->revoke($userId, $library->getId());

        return new LibraryAccess($library, granted: false);
    }
}
