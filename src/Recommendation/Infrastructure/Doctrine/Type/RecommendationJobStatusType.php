<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Doctrine\Type;

use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class RecommendationJobStatusType extends Type
{
    public const NAME = 'recommendation_job_status';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?RecommendationJobStatus
    {
        if ($value === null || $value instanceof RecommendationJobStatus) {
            return $value;
        }

        return RecommendationJobStatus::from($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof RecommendationJobStatus) {
            return $value->value;
        }

        if (is_string($value)) {
            return RecommendationJobStatus::from($value)->value;
        }

        throw new \InvalidArgumentException(sprintf(
            'Expected %s or null, got %s.',
            RecommendationJobStatus::class,
            get_debug_type($value),
        ));
    }
}
