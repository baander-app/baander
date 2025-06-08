<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Url extends Data
{
    public function __construct(public mixed $url, public mixed $id, public mixed $resource, public mixed $target_type, public mixed $count, public mixed $offset, public mixed $relation, public mixed $target, public mixed $type, public mixed $type_id, public mixed $ordering_key, public mixed $direction, public mixed $begin, public mixed $end, public mixed $ended, public mixed $source_credit, public mixed $target_credit)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            url: $data['url'] ?? null,
            id: $data['id'] ?? null,
            resource: $data['resource'] ?? null,
            target_type: $data['target-type'] ?? null,
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            relation: $data['relation'] ?? null,
            target: $data['target'] ?? null,
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