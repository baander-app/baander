<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class CitextType extends StringType
{
    public const NAME = 'citext';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'CITEXT';
    }

    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['citext'];
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
