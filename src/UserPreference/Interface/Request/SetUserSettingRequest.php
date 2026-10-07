<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetUserSettingRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[OA\Property(description: 'New value; validated against the setting definition', oneOf: [
            new OA\Schema(type: 'boolean'),
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
        ])]
        public mixed $value,
    ) {
    }
}
