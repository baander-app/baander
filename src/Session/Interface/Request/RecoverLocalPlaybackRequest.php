<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use App\Session\Interface\Validator\PlaybackCheckpoint;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RecoverLocalPlaybackRequest
{
    /** @param array{revision:int,media:?array{kind:string,publicId:string},currentTrackIndex:int,position:float} $checkpoint */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $incarnationId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $tabId,
        #[Assert\Regex(pattern: '/^[0-9a-f]{64}$/D')]
        #[\SensitiveParameter]
        public string $recoveryCredential,
        #[PlaybackCheckpoint]
        public array $checkpoint,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
