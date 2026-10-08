<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\UpdateLibraryCommand;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Renames or reorders a library, for the admin panel and `app:library:update`. */
final readonly class UpdateLibraryHandler
{
    public function __construct(
        private LibraryLookup $lookup,
        private LibraryRepositoryInterface $libraries,
    ) {
    }

    /**
     * @throws LibraryNotFoundException
     * @throws InvalidInputException when the name is blank
     */
    #[AsMessageHandler]
    public function __invoke(UpdateLibraryCommand $command): Library
    {
        $library = $this->lookup->byIdentifier($command->library);

        try {
            $library->updateMetadata(name: $command->name, sortOrder: $command->sortOrder);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), previous: $exception);
        }

        $this->libraries->save($library);

        return $library;
    }
}
