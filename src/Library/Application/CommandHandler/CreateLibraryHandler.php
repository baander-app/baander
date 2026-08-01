<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\CreateLibraryCommand;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CreateLibraryHandler
{
    public function __construct(
        private readonly LibraryRepositoryInterface $libraryRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CreateLibraryCommand $command): Library
    {
        if ($this->libraryRepository->findBySlug($command->getSlug()) !== null) {
            throw new \RuntimeException('A library with this slug already exists.');
        }

        $library = Library::create(
            $command->getName(),
            $command->getSlug(),
            $command->getPath(),
            $command->getType(),
            $command->getFilesystemType(),
            $command->getSortOrder(),
        );

        $this->libraryRepository->save($library);

        return $library;
    }
}
