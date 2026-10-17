<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure;

use App\QoL\Application\Port\QoLJobIdentityInterface;
use App\Shared\Domain\Model\PublicId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class QoLJobIdentity implements QoLJobIdentityInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function publicIds(array $internalIds): array
    {
        if ($internalIds === []) {
            return [];
        }
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT id, public_id FROM transcode_jobs WHERE id IN (:ids)',
            ['ids' => array_values(array_unique($internalIds))], ['ids' => ArrayParameterType::STRING],
        );
        $result = [];
        foreach ($rows as $id => $publicId) {
            $result[(string) $id] = PublicId::fromString((string) $publicId)->toString();
        }
        return $result;
    }
}
