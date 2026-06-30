<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdatePartyMemberRequest',
    properties: [
        new OA\Property(property: 'audioProfileId', type: 'string', nullable: true),
        new OA\Property(property: 'subtitleTrackId', type: 'string', nullable: true),
    ],
)]
final readonly class UpdatePartyMemberRequest
{
    public function __construct(
        public ?string $audioProfileId = null,
        public ?string $subtitleTrackId = null,
    ) {
    }
}
