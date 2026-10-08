<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\CreateLibraryCommand;
use App\Library\Application\Exception\LibrarySlugTakenException;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\ValueObject\FilesystemType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Creates a library, for the admin panel and `app:library:create`, and grants the named user access. */
final readonly class CreateLibraryHandler
{
    public function __construct(
        private LibraryRepositoryInterface $libraryRepository,
        private LibraryAccessPortInterface $libraryAccess,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws InvalidInputException     when a field is invalid
     * @throws LibrarySlugTakenException when another library has the slug
     */
    #[AsMessageHandler]
    public function __invoke(CreateLibraryCommand $command): Library
    {
        $library = $this->library($command);

        if ($this->libraryRepository->findBySlug($library->getSlug()) !== null) {
            throw LibrarySlugTakenException::forSlug($library->getSlug()->toString());
        }

        $this->transaction->run(function () use ($library, $command): void {
            $this->libraryRepository->save($library);

            if ($command->grantTo !== null) {
                $this->libraryAccess->grant($command->grantTo, $library->getId());
            }
        });

        return $library;
    }

    private function library(CreateLibraryCommand $command): Library
    {
        $type = LibraryType::tryFrom($command->type) ?? throw new InvalidInputException(sprintf(
            'Invalid library type "%s". Allowed: %s.',
            $command->type,
            implode(', ', array_column(LibraryType::cases(), 'value')),
        ));
        $filesystemType = FilesystemType::tryFrom($command->filesystemType) ?? throw new InvalidInputException(sprintf(
            'Invalid filesystem type "%s". Allowed: %s.',
            $command->filesystemType,
            implode(', ', array_column(FilesystemType::cases(), 'value')),
        ));

        try {
            return Library::create(
                $command->name,
                $command->slug !== null ? new LibrarySlug($command->slug) : LibrarySlug::fromName($command->name),
                new LibraryPath($command->path),
                $type,
                $filesystemType,
                $command->sortOrder,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), previous: $exception);
        }
    }
}
