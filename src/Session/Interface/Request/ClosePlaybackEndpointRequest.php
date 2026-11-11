<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use App\Session\Interface\Validator\PlaybackCheckpoint;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClosePlaybackEndpointRequest
{
    /** @param array{revision:int,media:?array{kind:string,publicId:string},currentTrackIndex:int,position:float} $checkpoint */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $incarnationId,
        #[PlaybackCheckpoint]
        public array $checkpoint,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
