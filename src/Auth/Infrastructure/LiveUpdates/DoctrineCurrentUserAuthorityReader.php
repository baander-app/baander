<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\LiveUpdates;

use App\Auth\Application\DTO\CurrentUserAuthority;
use App\Auth\Application\Port\CurrentUserAuthorityReader;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class DoctrineCurrentUserAuthorityReader implements CurrentUserAuthorityReader
{
    public function __construct(private Connection $connection) {}

    public function read(Uuid $principal): ?CurrentUserAuthority
    {
        $row = $this->connection->fetchAssociative('SELECT roles::text AS roles, CASE WHEN disabled THEN 1 ELSE 0 END AS disabled FROM users WHERE id = ?', [$principal->toString()]);
        if ($row === false) {
            return null;
        }
        $roles = json_decode($row['roles'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($roles) || !array_is_list($roles)) {
            throw new \UnexpectedValueException('Current user roles must be a list.');
        }
        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new \UnexpectedValueException('Current user roles must contain strings.');
            }
        }
        $roles = array_unique($roles);
        sort($roles, SORT_STRING);

        return new CurrentUserAuthority($roles, (int) $row['disabled'] === 1);
    }
}
