<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\WebSocketIdentityLookupInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class WebSocketIdentityLookup implements WebSocketIdentityLookupInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function partyId(PublicId $publicId): ?Uuid
    {
        $id = $this->connection->fetchOne('SELECT id FROM party_sessions WHERE public_id = :id', ['id' => $publicId->toString()]);

        return is_string($id) ? Uuid::fromString($id) : null;
    }

    public function userPublicId(Uuid $userId): ?PublicId
    {
        $id = $this->connection->fetchOne('SELECT public_id FROM users WHERE id = :id', ['id' => $userId->toString()]);

        return is_string($id) ? PublicId::fromString($id) : null;
    }

    public function transcodeSessionId(PublicId $publicId, Uuid $ownerId): ?Uuid
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM transcode_sessions WHERE public_id = :id AND user_id = :owner',
            ['id' => $publicId->toString(), 'owner' => $ownerId->toString()],
        );

        return is_string($id) ? Uuid::fromString($id) : null;
    }
}
