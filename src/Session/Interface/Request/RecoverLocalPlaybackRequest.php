<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

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
        #[Assert\Collection(fields: [
            'revision' => [new Assert\Type('integer'), new Assert\PositiveOrZero()],
            'media' => [new Assert\AtLeastOneOf(constraints: [new Assert\IsNull(), new Assert\Collection(fields: [
                'kind' => [new Assert\Choice(choices: ['song', 'video'])],
                'publicId' => [new Assert\Regex(pattern: '/^[0-9a-zA-Z_-]{21}$/D')],
            ])])],
            'currentTrackIndex' => [new Assert\Type('integer'), new Assert\PositiveOrZero()],
            'position' => [new Assert\Type('numeric'), new Assert\PositiveOrZero()],
        ])]
        public array $checkpoint,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
