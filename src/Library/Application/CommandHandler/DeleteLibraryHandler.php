<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\DeleteLibraryCommand;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Deletes a library, for the admin panel and `app:library:delete`. */
final readonly class DeleteLibraryHandler
{
    public function __construct(
        private LibraryLookup $lookup,
        private LibraryRepositoryInterface $libraries,
    ) {
    }

    /** @throws LibraryNotFoundException */
    #[AsMessageHandler]
    public function __invoke(DeleteLibraryCommand $command): void
    {
        $this->libraries->delete($this->lookup->byIdentifier($command->library));
    }
}
