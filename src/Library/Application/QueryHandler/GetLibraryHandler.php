<?php

declare(strict_types=1);

namespace App\Library\Application\QueryHandler;

use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Query\GetLibraryQuery;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Domain\Model\Library;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class GetLibraryHandler
{
    public function __construct(
        private LibraryLookup $lookup,
    ) {
    }

    /** @throws LibraryNotFoundException when the reader may not see the library either */
    #[AsMessageHandler]
    public function __invoke(GetLibraryQuery $query): Library
    {
        return $this->lookup->byIdentifier($query->library, $query->scope);
    }
}
