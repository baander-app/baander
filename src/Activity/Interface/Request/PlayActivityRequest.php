<?php

declare(strict_types=1);

namespace App\Activity\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'songId' => new OA\Schema(
            description: 'Public ID of the song being played',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
            default: null,
        ),
        'albumId' => new OA\Schema(
            description: 'Public ID of the album',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
            default: null,
        ),
        'artistId' => new OA\Schema(
            description: 'Public ID of the artist',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
            default: null,
        ),
        'movieId' => new OA\Schema(
            description: 'Public ID of the movie',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
            default: null,
        ),
        'platform' => new OA\Schema(
            description: 'Playback platform (e.g. web, mobile, desktop)',
            type: 'string',
            nullable: true,
            maxLength: PlayActivityRequest::LABEL_LENGTH,
            default: null,
        ),
        'player' => new OA\Schema(
            description: 'Player application name',
            type: 'string',
            nullable: true,
            maxLength: PlayActivityRequest::LABEL_LENGTH,
            default: null,
        ),
    ],
    component: 'PlayActivityRequest',
)]
final readonly class PlayActivityRequest
{
    /** The longest platform or player name stored with a play. */
    public const int LABEL_LENGTH = 64;

    public function __construct(
        public ?string $songId = null,
        public ?string $albumId = null,
        public ?string $artistId = null,
        public ?string $movieId = null,
        #[Assert\Length(max: self::LABEL_LENGTH)]
        public ?string $platform = null,
        #[Assert\Length(max: self::LABEL_LENGTH)]
        public ?string $player = null,
    ) {
    }
}
