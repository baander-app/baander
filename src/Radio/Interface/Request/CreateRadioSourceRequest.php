<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateRadioSourceRequest
{
    /** @param array<string, mixed> $syncConfig */
    public function __construct(
        #[Assert\NotBlank]
        public string $name,

        #[Assert\NotBlank]
        public string $type,

        #[Assert\NotBlank]
        #[Assert\Url]
        public string $syncUrl,

        #[Assert\Type('array')]
        #[OA\Property(type: 'object', default: new \stdClass(), additionalProperties: new OA\AdditionalProperties(nullable: true))]
        public array $syncConfig = [],

        public ?string $syncSchedule = null,
    ) {
    }
}
