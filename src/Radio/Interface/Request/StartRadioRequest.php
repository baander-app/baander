<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'stationId' => new OA\Schema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
        ),
        'streamUrl' => new OA\Schema(
            type: 'string',
            maxLength: StartRadioRequest::STREAM_URL_LENGTH,
        ),
    ],
    required: ['stationId', 'streamUrl'],
    component: 'StartRadioRequest',
)]
final readonly class StartRadioRequest
{
    public const int STREAM_URL_LENGTH = 2048;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(exactly: PublicId::LENGTH)]
        #[Assert\Regex(pattern: PublicId::REGEX)]
        public string $stationId,

        #[Assert\NotBlank]
        #[Assert\Length(max: self::STREAM_URL_LENGTH)]
        #[Assert\Url]
        public string $streamUrl,
    ) {
    }
}
