<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'value' => new OA\Schema(
            description: 'New value; validated against the setting definition',
            oneOf: [
                new OA\Schema(type: 'boolean'),
                new OA\Schema(type: 'integer'),
                new OA\Schema(type: 'string'),
            ],
        ),
    ],
    required: ['value'],
    component: 'SetUserSettingRequest',
)]
final readonly class SetUserSettingRequest
{
    public function __construct(
        #[Assert\NotNull]
        public mixed $value,
    ) {
    }
}
