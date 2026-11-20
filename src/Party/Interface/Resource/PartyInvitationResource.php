<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Application\DTO\PartyInvitationView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PartyInvitationResource',
    properties: [
        new OA\Property(property: 'userId', description: 'Invited user public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'invitedAt', description: 'Invitation timestamp', type: 'string', format: 'date-time'),
    ],
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
