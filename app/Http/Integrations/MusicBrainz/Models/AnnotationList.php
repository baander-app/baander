<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class AnnotationList extends Data
{
    public function __construct(public mixed $count, public mixed $offset, public mixed $annotation, public mixed $text, public mixed $type, public mixed $entity, public mixed $name)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            annotation: $data['annotation'] ?? null,
            text: $data['text'] ?? null,
            type: $data['type'] ?? null,
            entity: $data['entity'] ?? null,
            name: $data['name'] ?? null
        );
    }
}