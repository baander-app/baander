<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\CreateLibraryCommand;
use App\Library\Application\Exception\InvalidLibraryTypeException;
use App\Library\Application\Exception\LibraryRootOverlapsException;
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
     * @throws InvalidInputException        when a field is invalid
     * @throws LibrarySlugTakenException    when another library has the slug
     * @throws LibraryRootOverlapsException when the root lies inside another library's root or contains it
     */
    #[AsMessageHandler]
    public function __invoke(CreateLibraryCommand $command): Library
    {
        $library = $this->library($command);

        if ($this->libraryRepository->findBySlug($library->getSlug()) !== null) {
            throw LibrarySlugTakenException::forSlug($library->getSlug()->toString());
        }

        $this->refuseOverlappingRoot($library);

        $this->transaction->run(function () use ($library, $command): void {
            $this->libraryRepository->save($library);

            if ($command->grantTo !== null) {
                $this->libraryAccess->grant($command->grantTo, $library->getId());
            }
        });

        return $library;
    }

    /**
     * A file under two library roots would belong to both libraries. Roots compare by their real
     * paths where they resolve, so a symlink cannot hide an overlap, and as written otherwise,
     * since a root may not exist yet; a root contains another only on a `/` boundary.
     */
    private function refuseOverlappingRoot(Library $library): void
    {
        $root = self::comparable($library->getPath()->toString());

        foreach ($this->libraryRepository->findAllOrdered() as $other) {
            $otherRoot = self::comparable($other->getPath()->toString());

            if (self::contains($otherRoot, $root) || self::contains($root, $otherRoot)) {
                throw LibraryRootOverlapsException::with(
                    $library->getPath()->toString(),
                    $other->getSlug()->toString(),
                    $other->getPath()->toString(),
                );
            }
        }
    }

    /** Whether $path is $root or lies under it; a root resolved to `/` contains every path. */
    private static function contains(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    private static function comparable(string $path): string
    {
        return realpath($path) ?: $path;
    }

    private function library(CreateLibraryCommand $command): Library
    {
        $type = LibraryType::tryFrom($command->type) ?? throw InvalidLibraryTypeException::forType($command->type);
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
