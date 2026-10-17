<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure;

use App\Radio\Application\Port\RadioPublicIdentityInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class RadioPublicIdentity implements RadioPublicIdentityInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function userPublicId(Uuid $userId): PublicId
    {
        $value = $this->connection->fetchOne('SELECT public_id FROM users WHERE id = :id', ['id' => $userId->toString()]);
        if (!is_string($value)) {
            throw new NotFoundException('Radio user not found.');
        }

        return PublicId::fromString($value);
    }
}
