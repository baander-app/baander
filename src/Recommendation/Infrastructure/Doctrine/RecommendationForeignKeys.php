<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclaration;
use App\Shared\Infrastructure\Doctrine\EventListener\ForeignKeyDeclarationProviderInterface;

/** Constraints from Version20261006280000 on recommendation job columns mapped as scalar IDs. */
final class RecommendationForeignKeys implements ForeignKeyDeclarationProviderInterface
{
    public function foreignKeys(): iterable
    {
        yield new ForeignKeyDeclaration('fk_recommendation_jobs_user_id', 'recommendation_jobs', 'user_id', 'users', 'id', 'SET NULL');
        yield new ForeignKeyDeclaration('fk_recommendation_jobs_original_job_id', 'recommendation_jobs', 'original_job_id', 'recommendation_jobs', 'id', 'SET NULL');
    }
}
