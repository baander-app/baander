<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\LibraryProviderOrder;
use App\Catalog\Domain\Model\LibraryProviderOrderState;
use App\Catalog\Domain\Repository\LibraryProviderOrderRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class LibraryProviderOrderRepository implements LibraryProviderOrderRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function findByLibrary(Uuid $libraryId): ?LibraryProviderOrder
    {
        $providers = $this->connection->fetchOne(
            'SELECT provider_ids FROM library_provider_orders WHERE library_id = :library',
            ['library' => $libraryId],
            ['library' => 'uuid'],
        );
        if ($providers === false) {
            return null;
        }
        /** @var list<string> $providerIds */
        $providerIds = json_decode($providers, true, flags: JSON_THROW_ON_ERROR);

        return LibraryProviderOrder::reconstitute(new LibraryProviderOrderState($libraryId, $providerIds));
    }

    public function save(LibraryProviderOrder $order): void
    {
        // DQL has no upsert to replace the whole order atomically, including concurrent first writes.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO library_provider_orders (library_id, provider_ids)
                VALUES (:library, :providers)
                ON CONFLICT (library_id) DO UPDATE SET provider_ids = EXCLUDED.provider_ids
                SQL,
            ['library' => $order->getLibraryId(), 'providers' => $order->getProviderIds()],
            ['library' => 'uuid', 'providers' => Types::JSON],
        );
    }
}
