<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Annotation extends Data
{
    public function __construct(public mixed $text, public mixed $type, public mixed $entity, public mixed $name)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            text: $data['text'] ?? null,
            type: $data['type'] ?? null,
            entity: $data['entity'] ?? null,
            name: $data['name'] ?? null
        );
    }
}