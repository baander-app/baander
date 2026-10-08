<?php

declare(strict_types=1);

namespace App\Library\Application\QueryHandler;

use App\Library\Application\Exception\InvalidLibraryTypeException;
use App\Library\Application\Query\ListLibrariesQuery;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ListLibrariesHandler
{
    public function __construct(
        private LibraryRepositoryInterface $libraries,
    ) {
    }

    /**
     * @return list<Library>
     *
     * @throws InvalidLibraryTypeException for an unknown type
     */
    #[AsMessageHandler]
    public function __invoke(ListLibrariesQuery $query): array
    {
        $type = null;
        if ($query->type !== null) {
            $type = LibraryType::tryFrom($query->type) ?? throw InvalidLibraryTypeException::forType($query->type);
        }

        return array_values($this->libraries->findVisible($query->scope, $type));
    }
}
