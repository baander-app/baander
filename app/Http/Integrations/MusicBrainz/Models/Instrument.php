<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Instrument extends Data
{
    public function __construct(public mixed $instrument, public mixed $id, public mixed $type, public mixed $type_id, public mixed $name, public mixed $disambiguation, public mixed $description, public mixed $annotation, public mixed $text, public mixed $entity, public mixed $count, public mixed $offset, public mixed $alias, public mixed $locale, public mixed $sort_name, public mixed $primary, public mixed $begin_date, public mixed $end_date, public mixed $tag, public mixed $user_tag, public mixed $genre, public mixed $target_type, public mixed $relation, public mixed $target, public mixed $ordering_key, public mixed $direction, public mixed $begin, public mixed $end, public mixed $ended, public mixed $source_credit, public mixed $target_credit, public mixed $user_genre)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            instrument: $data['instrument'] ?? null,
            id: $data['id'] ?? null,
            type: $data['type'] ?? null,
            type_id: $data['type-id'] ?? null,
            name: $data['name'] ?? null,
            disambiguation: $data['disambiguation'] ?? null,
            description: $data['description'] ?? null,
            annotation: $data['annotation'] ?? null,
            text: $data['text'] ?? null,
            entity: $data['entity'] ?? null,
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            alias: $data['alias'] ?? null,
            locale: $data['locale'] ?? null,
            sort_name: $data['sort-name'] ?? null,
            primary: $data['primary'] ?? null,
            begin_date: $data['begin-date'] ?? null,
            end_date: $data['end-date'] ?? null,
            tag: $data['tag'] ?? null,
            user_tag: $data['user-tag'] ?? null,
            genre: $data['genre'] ?? null,
            target_type: $data['target-type'] ?? null,
            relation: $data['relation'] ?? null,
            target: $data['target'] ?? null,
            ordering_key: $data['ordering-key'] ?? null,
            direction: $data['direction'] ?? null,
            begin: $data['begin'] ?? null,
            end: $data['end'] ?? null,
            ended: $data['ended'] ?? null,
            source_credit: $data['source-credit'] ?? null,
            target_credit: $data['target-credit'] ?? null,
            user_genre: $data['user-genre'] ?? null
        );
    }
}