<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Application\DTO\PartyInvitationView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'userId' => new OA\Schema(
            description: 'Invited user public ID',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'invitedAt' => new OA\Schema(
            description: 'Invitation timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['userId', 'invitedAt'],
    component: 'PartyInvitationResource',
)]
final class PartyInvitationResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof PartyInvitationView);

        return [
            'userId' => $source->userId->toString(),
            'invitedAt' => $source->model->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
