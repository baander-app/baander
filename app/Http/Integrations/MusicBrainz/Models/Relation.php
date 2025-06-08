<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Relation extends Data
{
    public function __construct(public mixed $relation, public mixed $target, public mixed $id, public mixed $type, public mixed $type_id, public mixed $ordering_key, public mixed $direction, public mixed $begin, public mixed $end, public mixed $ended, public mixed $source_credit, public mixed $target_credit)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            relation: $data['relation'] ?? null,
            target: $data['target'] ?? null,
            id: $data['id'] ?? null,
            type: $data['type'] ?? null,
            type_id: $data['type-id'] ?? null,
            ordering_key: $data['ordering-key'] ?? null,
            direction: $data['direction'] ?? null,
            begin: $data['begin'] ?? null,
            end: $data['end'] ?? null,
            ended: $data['ended'] ?? null,
            source_credit: $data['source-credit'] ?? null,
            target_credit: $data['target-credit'] ?? null
        );
    }
}