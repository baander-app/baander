<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'audioProfileId' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'subtitleTrackId' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    component: 'UpdatePartyMemberRequest',
)]
final readonly class UpdatePartyMemberRequest
{
    public function __construct(
        public ?string $audioProfileId = null,
        public ?string $subtitleTrackId = null,
    ) {
    }
}
