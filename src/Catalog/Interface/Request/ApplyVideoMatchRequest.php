<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'provider' => new OA\Schema(type: 'string', enum: ['tvdb', 'tmdb', 'local']),
        'candidateId' => new OA\Schema(type: 'string', minLength: 1),
    ],
    required: ['provider', 'candidateId'],
    component: 'ApplyVideoMatchRequest',
)]
final readonly class ApplyVideoMatchRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['tvdb', 'tmdb', 'local'])]
        public string $provider,
        #[Assert\NotBlank]
        public string $candidateId,
    ) {}
}
