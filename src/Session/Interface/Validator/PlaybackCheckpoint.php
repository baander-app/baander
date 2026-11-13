<?php

declare(strict_types=1);

namespace App\Session\Interface\Validator;

use Attribute;
use Symfony\Component\Validator\Constraints as Assert;

/** The cursor an endpoint held when it went away, with the session revision, media and queue position it belongs to. */
#[Attribute]
final class PlaybackCheckpoint extends Assert\Compound
{
    /**
     * @param array<string,mixed> $options
     * @return list<Assert\Collection>
     */
    protected function getConstraints(array $options): array
    {
        return [new Assert\Collection(fields: [
            'revision' => [new Assert\Type('integer'), new Assert\PositiveOrZero()],
            'media' => [new Assert\AtLeastOneOf(constraints: [new Assert\IsNull(), new Assert\Collection(fields: [
                'kind' => [new Assert\Choice(choices: ['song', 'video'])],
                'publicId' => [new Assert\Regex(pattern: '/^[0-9a-zA-Z_-]{21}$/D')],
            ])])],
            'currentTrackIndex' => [new Assert\Type('integer'), new Assert\PositiveOrZero()],
            'position' => [new Assert\Type('numeric'), new Assert\PositiveOrZero()],
        ])];
    }
}
