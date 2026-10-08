<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Resource;

use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use App\Shared\Interface\Resource\AbstractResource;

/**
 * A started recommendation job, as the generate and requeue routes return it: `counts`
 * only when the job ran in the serving process.
 */
final class RecommendationGenerationResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof RecommendationGenerationResult);

        $data = [
            'job_id' => $source->jobId,
            'public_id' => $source->publicId,
            'mode' => $source->mode,
            'status' => $source->status,
            'execution' => $source->execution,
        ];
        if (!$source->isAsync()) {
            $data['counts'] = $source->counts;
        }

        return $data;
    }
}
