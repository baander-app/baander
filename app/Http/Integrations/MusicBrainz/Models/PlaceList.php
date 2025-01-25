<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class PlaceList extends Data
{
    public function __construct(public mixed $count, public mixed $offset, public mixed $place, public mixed $id, public mixed $type, public mixed $type_id, public mixed $name, public mixed $disambiguation, public mixed $address, public mixed $coordinates, public mixed $latitude, public mixed $longitude, public mixed $annotation, public mixed $text, public mixed $entity, public mixed $area, public mixed $sort_name, public mixed $iso_3166_1_code, public mixed $iso_3166_2_code, public mixed $iso_3166_3_code, public mixed $life_span, public mixed $begin, public mixed $end, public mixed $ended, public mixed $alias, public mixed $locale, public mixed $primary, public mixed $begin_date, public mixed $end_date, public mixed $tag, public mixed $user_tag, public mixed $genre, public mixed $target_type, public mixed $relation, public mixed $target, public mixed $ordering_key, public mixed $direction, public mixed $source_credit, public mixed $target_credit, public mixed $user_genre)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            place: $data['place'] ?? null,
            id: $data['id'] ?? null,
            type: $data['type'] ?? null,
            type_id: $data['type-id'] ?? null,
            name: $data['name'] ?? null,
            disambiguation: $data['disambiguation'] ?? null,
            address: $data['address'] ?? null,
            coordinates: $data['coordinates'] ?? null,
            latitude: $data['latitude'] ?? null,
            longitude: $data['longitude'] ?? null,
            annotation: $data['annotation'] ?? null,
            text: $data['text'] ?? null,
            entity: $data['entity'] ?? null,
            area: $data['area'] ?? null,
            sort_name: $data['sort-name'] ?? null,
            iso_3166_1_code: $data['iso-3166-1-code'] ?? null,
            iso_3166_2_code: $data['iso-3166-2-code'] ?? null,
            iso_3166_3_code: $data['iso-3166-3-code'] ?? null,
            life_span: $data['life-span'] ?? null,
            begin: $data['begin'] ?? null,
            end: $data['end'] ?? null,
            ended: $data['ended'] ?? null,
            alias: $data['alias'] ?? null,
            locale: $data['locale'] ?? null,
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
            source_credit: $data['source-credit'] ?? null,
            target_credit: $data['target-credit'] ?? null,
            user_genre: $data['user-genre'] ?? null
        );
    }
}