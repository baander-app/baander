<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Service;

use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;

/** Loads a recommendation job by the public ID the admin API and commands take. */
final readonly class RecommendationJobFinder
{
    public function __construct(
        private RecommendationJobPortInterface $jobs,
    ) {
    }

    /** @throws NotFoundException also for a malformed ID, which no job can have */
    public function byPublicId(string $publicId): RecommendationJob
    {
        try {
            $id = PublicId::fromString($publicId);
        } catch (\InvalidArgumentException) {
            throw self::notFound($publicId);
        }

        return $this->jobs->getByPublicId($id) ?? throw self::notFound($publicId);
    }

    private static function notFound(string $publicId): NotFoundException
    {
        return new NotFoundException('Recommendation job not found.', ['publicId' => $publicId]);
    }
}
