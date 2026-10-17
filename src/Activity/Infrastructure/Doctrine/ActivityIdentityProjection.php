<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine;

use App\Activity\Application\Port\ActivityIdentityProjectionPortInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\Connection;

final readonly class ActivityIdentityProjection implements ActivityIdentityProjectionPortInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function readScope(Uuid $userId, bool $admin): LibraryReadScope
    {
        if ($admin) {
            return LibraryReadScope::unrestricted();
        }
        $ids = $this->connection->fetchFirstColumn('SELECT library_id FROM user_library_access WHERE user_id = :userId', ['userId' => $userId->toString()]);

        return LibraryReadScope::restricted(array_map(Uuid::fromString(...), $ids));
    }

    public function userPublicId(Uuid $userId): PublicId
    {
        $value = $this->connection->fetchOne('SELECT public_id FROM users WHERE id = :id', ['id' => $userId->toString()]);

        return is_string($value) ? PublicId::fromString($value) : throw new NotFoundException('Activity user not found.');
    }

    public function artistPublicId(Uuid $artistId): ?PublicId
    {
        $value = $this->connection->fetchOne('SELECT public_id FROM artists WHERE id = :id', ['id' => $artistId->toString()]);

        return is_string($value) ? PublicId::fromString($value) : null;
    }
}
