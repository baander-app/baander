<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\Model\PublicId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;

final class PublicIdType extends Type
{
    public const NAME = 'public_id';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TEXT';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?PublicId
    {
        if ($value === null || $value instanceof PublicId) {
            return $value;
        }

        return new PublicId($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof PublicId) {
            return $value->toString();
        }

        throw new \InvalidArgumentException(sprintf(
            'Expected %s or null, got %s.',
            PublicId::class,
            get_debug_type($value),
        ));
    }
}
