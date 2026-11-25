<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use App\Playlist\Application\PlaylistLimits;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            nullable: true,
            maxLength: PlaylistLimits::NAME_LENGTH,
            example: 'Updated Playlist Name',
            default: null,
        ),
        'description' => new OA\Schema(
            type: 'string',
            nullable: true,
            maxLength: PlaylistLimits::DESCRIPTION_LENGTH,
            example: 'Updated description',
            default: null,
        ),
        'isPublic' => new OA\Schema(
            type: 'boolean',
            nullable: true,
            example: true,
            default: null,
        ),
        'isCollaborative' => new OA\Schema(
            description: "The owner's switch for collaborator editing; collaborators keep viewing while it is off",
            type: 'boolean',
            nullable: true,
            example: false,
            default: null,
        ),
    ],
    component: 'UpdatePlaylistRequest',
)]
final readonly class UpdatePlaylistRequest
{
    public function __construct(
        #[Assert\Length(max: PlaylistLimits::NAME_LENGTH)]
        public ?string $name = null,
        #[Assert\Length(max: PlaylistLimits::DESCRIPTION_LENGTH)]
        public ?string $description = null,
        public ?bool $isPublic = null,
        public ?bool $isCollaborative = null,
    ) {
    }
}
