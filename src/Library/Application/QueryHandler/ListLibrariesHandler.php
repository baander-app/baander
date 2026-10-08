<?php

declare(strict_types=1);

namespace App\Library\Application\QueryHandler;

use App\Library\Application\Query\ListLibrariesQuery;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\Exception\InvalidInputException;
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
     * @throws InvalidInputException for an unknown type
     */
    #[AsMessageHandler]
    public function __invoke(ListLibrariesQuery $query): array
    {
        $type = null;
        if ($query->type !== null) {
            $type = LibraryType::tryFrom($query->type) ?? throw new InvalidInputException(sprintf(
                'Invalid library type "%s". Allowed: %s.',
                $query->type,
                implode(', ', array_column(LibraryType::cases(), 'value')),
            ));
        }

        return array_values($this->libraries->findVisible($query->scope, $type));
    }
}
