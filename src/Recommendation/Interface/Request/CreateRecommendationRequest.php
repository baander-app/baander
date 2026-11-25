<?php

declare(strict_types=1);

namespace App\Recommendation\Interface\Request;

use App\Recommendation\Application\RecommendationLimits;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The properties keep the API's snake_case names, so a validation error names the field the
 * client sent.
 */
#[OA\Schema(
    type: 'object',
    properties: [
        'source_type' => new OA\Schema(type: 'string', enum: RecommendationLimits::ENTITY_TYPES, example: 'song'),
        'source_id' => new OA\Schema(description: 'Public ID of the source entity', type: 'string', example: 'V1StGXR8_Z5jdHi6B-myT'),
        'target_type' => new OA\Schema(type: 'string', enum: RecommendationLimits::ENTITY_TYPES, example: 'song'),
        'target_id' => new OA\Schema(description: 'Public ID of the target entity', type: 'string', example: 'Uakgb_J5m9g-0JDMbcJqL'),
        'score' => new OA\Schema(
            description: 'Relevance in the range the recommendation generator scores in',
            type: 'number',
            format: 'double',
            maximum: RecommendationLimits::MAX_SCORE,
            minimum: RecommendationLimits::MIN_SCORE,
            example: 0.85,
        ),
        'name' => new OA\Schema(type: 'string', maxLength: RecommendationLimits::NAME_LENGTH, example: 'default', default: 'default'),
        'position' => new OA\Schema(
            type: 'integer',
            format: 'int32',
            maximum: RecommendationLimits::MAX_POSITION,
            minimum: RecommendationLimits::MIN_POSITION,
            nullable: true,
            example: 1,
            default: null,
        ),
    ],
    required: ['source_type', 'source_id', 'target_type', 'target_id', 'score'],
    component: 'CreateRecommendationRequest',
)]
final readonly class CreateRecommendationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: RecommendationLimits::ENTITY_TYPES)]
        public string $source_type = '',
        #[Assert\NotBlank]
        public string $source_id = '',
        #[Assert\NotBlank]
        #[Assert\Choice(choices: RecommendationLimits::ENTITY_TYPES)]
        public string $target_type = '',
        #[Assert\NotBlank]
        public string $target_id = '',
        #[Assert\NotNull]
        #[Assert\Type('finite-float')]
        #[Assert\Range(min: RecommendationLimits::MIN_SCORE, max: RecommendationLimits::MAX_SCORE)]
        public ?float $score = null,
        #[Assert\Length(max: RecommendationLimits::NAME_LENGTH)]
        public string $name = 'default',
        #[Assert\Range(min: RecommendationLimits::MIN_POSITION, max: RecommendationLimits::MAX_POSITION)]
        public ?int $position = null,
    ) {
    }
}
