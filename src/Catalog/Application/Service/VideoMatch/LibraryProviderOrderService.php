<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Catalog\Domain\Model\LibraryProviderOrder;
use App\Catalog\Domain\Repository\LibraryProviderOrderRepositoryInterface;
use App\Library\Application\Port\LibraryTypeLookupInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use InvalidArgumentException;

final readonly class LibraryProviderOrderService
{
    public function __construct(
        private LibraryTypeLookupInterface $libraries,
        private VideoMetadataPortInterface $providers,
        private LibraryProviderOrderRepositoryInterface $orders,
    ) {}

    public function getByIdentifier(string $identifier): LibraryProviderOrder
    {
        return $this->get($this->adminLibraryId($identifier));
    }

    public function get(Uuid $libraryId): LibraryProviderOrder
    {
        $supported = $this->supported($libraryId);

        return $this->orders->findByLibrary($libraryId)
            ?? LibraryProviderOrder::fromProviders($libraryId, $supported, $supported);
    }

    /** @param list<string> $providers */
    public function setByIdentifier(string $identifier, array $providers): LibraryProviderOrder
    {
        $libraryId = $this->adminLibraryId($identifier);
        $supported = $this->supported($libraryId);
        try {
            $order = LibraryProviderOrder::fromProviders($libraryId, $providers, $supported);
        } catch (InvalidArgumentException $error) {
            throw new InvalidInputException($error->getMessage(), ['providers' => $supported], $error);
        }
        $this->orders->save($order);

        return $order;
    }

    private function adminLibraryId(string $identifier): Uuid
    {
        // Provider order is administered across all libraries.
        return $this->libraries->resolveId($identifier, LibraryReadScope::unrestricted());
    }

    /** @return list<string> */
    private function supported(Uuid $libraryId): array
    {
        $kind = match ($this->libraries->findType($libraryId)) {
            LibraryType::Movie => VideoKind::Movie,
            LibraryType::TvShow => VideoKind::Series,
            null => throw new NotFoundException('Library not found.'),
            default => throw new InvalidInputException('Only TV and movie libraries have a video provider order.'),
        };

        return array_column($this->providers->providers($kind), 'value');
    }
}
