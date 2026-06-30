<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\Model\JobStatus;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class JobStatusType extends Type
{
    public const NAME = 'job_status';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?JobStatus
    {
        if ($value === null || $value instanceof JobStatus) {
            return $value;
        }

        return JobStatus::from($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof JobStatus) {
            return $value->value;
        }

        // Doctrine ORM's PersisterHelper unwraps BackedEnum to its raw value
        // before calling convertToDatabaseValue, so handle plain strings too.
        if (is_string($value)) {
            return JobStatus::from($value)->value;
        }

        throw new \InvalidArgumentException(sprintf(
            'Expected %s or null, got %s.',
            JobStatus::class,
            get_debug_type($value),
        ));
    }
}
