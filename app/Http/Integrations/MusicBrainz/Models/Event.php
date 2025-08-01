<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Event extends Data
{
    public function __construct(public mixed $event, public mixed $id, public mixed $type, public mixed $type_id, public mixed $name, public mixed $disambiguation, public mixed $cancelled, public mixed $life_span, public mixed $begin, public mixed $end, public mixed $time, public mixed $setlist, public mixed $annotation, public mixed $text, public mixed $entity, public mixed $count, public mixed $offset, public mixed $alias, public mixed $locale, public mixed $sort_name, public mixed $primary, public mixed $begin_date, public mixed $end_date, public mixed $tag, public mixed $user_tag, public mixed $genre, public mixed $target_type, public mixed $relation, public mixed $target, public mixed $ordering_key, public mixed $direction, public mixed $ended, public mixed $source_credit, public mixed $target_credit, public mixed $user_genre, public mixed $rating, public mixed $votes_count, public mixed $user_rating)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            event: $data['event'] ?? null,
            id: $data['id'] ?? null,
            type: $data['type'] ?? null,
            type_id: $data['type-id'] ?? null,
            name: $data['name'] ?? null,
            disambiguation: $data['disambiguation'] ?? null,
            cancelled: $data['cancelled'] ?? null,
            life_span: $data['life-span'] ?? null,
            begin: $data['begin'] ?? null,
            end: $data['end'] ?? null,
            time: $data['time'] ?? null,
            setlist: $data['setlist'] ?? null,
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
            ended: $data['ended'] ?? null,
            source_credit: $data['source-credit'] ?? null,
            target_credit: $data['target-credit'] ?? null,
            user_genre: $data['user-genre'] ?? null,
            rating: $data['rating'] ?? null,
            votes_count: $data['votes-count'] ?? null,
            user_rating: $data['user-rating'] ?? null
        );
    }
}