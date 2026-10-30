<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\LiveUpdates;

use App\Library\Application\Port\LibraryAudienceReader;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class DoctrineLibraryAudienceReader implements LibraryAudienceReader
{
    public function __construct(private Connection $connection) {}

    public function readableLibraryIds(Uuid $principal, bool $administrator): array
    {
        if ($administrator) {
            return $this->connection->fetchFirstColumn('SELECT id::text FROM libraries ORDER BY id');
        }

        return $this->connection->fetchFirstColumn(
            'SELECT library_id::text FROM user_library_access WHERE user_id = ? ORDER BY library_id',
            [$principal->toString()],
        );
    }
}
