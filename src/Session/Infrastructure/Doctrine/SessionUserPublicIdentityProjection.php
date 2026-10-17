<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine;

use App\Session\Application\Port\SessionUserPublicIdentityInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class SessionUserPublicIdentityProjection implements SessionUserPublicIdentityInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function publicIdByUserId(Uuid $userId): PublicId
    {
        $publicId = $this->connection->fetchOne(
            'SELECT public_id FROM users WHERE id = :id',
            ['id' => $userId->toString()],
        );
        if (!is_string($publicId)) {
            throw new NotFoundException('Session owner not found.');
        }

        return PublicId::fromString($publicId);
    }
}
