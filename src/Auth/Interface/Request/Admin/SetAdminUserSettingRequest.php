<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

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
    component: 'SetAdminUserSettingRequest',
)]
final readonly class SetAdminUserSettingRequest
{
    public function __construct(
        #[Assert\NotNull]
        public mixed $value,
    ) {
    }
}
