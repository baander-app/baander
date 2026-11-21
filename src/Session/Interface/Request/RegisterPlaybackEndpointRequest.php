<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'deviceId' => new OA\Schema(type: 'string'),
        'endpointId' => new OA\Schema(type: 'string'),
        'name' => new OA\Schema(
            type: 'string',
            minLength: 1,
            maxLength: 128,
            default: 'Browser',
        ),
        'capabilities' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
            minItems: 1,
            maxItems: 3,
            default: ['song', 'video'],
        ),
        'tabId' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'localRecoveryEligible' => new OA\Schema(type: 'boolean', default: false),
        'pageId' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    required: ['deviceId', 'endpointId'],
    component: 'RegisterPlaybackEndpointRequest',
)]
final readonly class RegisterPlaybackEndpointRequest
{
    /** @param list<string> $capabilities */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $deviceId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $endpointId,
        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 128)]
        public string $name = 'Browser',
        #[Assert\Count(min: 1, max: 3)]
        #[Assert\All(constraints: [new Assert\Choice(choices: ['song', 'video', 'volume'])])]
        public array $capabilities = ['song', 'video'],
        #[Assert\Uuid]
        public ?string $tabId = null,
        public bool $localRecoveryEligible = false,
        #[Assert\Uuid]
        public ?string $pageId = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
