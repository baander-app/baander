<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Application\DTO\PartyInvitationView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PartyInvitationResource',
    properties: [
        new OA\Property(property: 'userId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Invited user public ID'),
        new OA\Property(property: 'invitedAt', type: 'string', format: 'date-time', description: 'Invitation timestamp'),
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
