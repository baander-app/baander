<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Editor extends Data
{
    public function __construct(public mixed $editor, public mixed $name, public mixed $id, public mixed $member_since, public mixed $privs, public mixed $gender, public mixed $age, public mixed $homepage, public mixed $bio, public mixed $area, public mixed $type, public mixed $type_id, public mixed $sort_name, public mixed $disambiguation, public mixed $iso_3166_1_code, public mixed $iso_3166_2_code, public mixed $iso_3166_3_code, public mixed $annotation, public mixed $text, public mixed $entity, public mixed $life_span, public mixed $begin, public mixed $end, public mixed $ended, public mixed $count, public mixed $offset, public mixed $alias, public mixed $locale, public mixed $primary, public mixed $begin_date, public mixed $end_date, public mixed $tag, public mixed $user_tag, public mixed $genre, public mixed $target_type, public mixed $relation, public mixed $target, public mixed $ordering_key, public mixed $direction, public mixed $source_credit, public mixed $target_credit, public mixed $user_genre, public mixed $language, public mixed $fluency, public mixed $edit_information, public mixed $edits_accepted, public mixed $edits_rejected, public mixed $auto_edits_accepted, public mixed $edits_failed)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            editor: $data['editor'] ?? null,
            name: $data['name'] ?? null,
            id: $data['id'] ?? null,
            member_since: $data['member-since'] ?? null,
            privs: $data['privs'] ?? null,
            gender: $data['gender'] ?? null,
            age: $data['age'] ?? null,
            homepage: $data['homepage'] ?? null,
            bio: $data['bio'] ?? null,
            area: $data['area'] ?? null,
            type: $data['type'] ?? null,
            type_id: $data['type-id'] ?? null,
            sort_name: $data['sort-name'] ?? null,
            disambiguation: $data['disambiguation'] ?? null,
            iso_3166_1_code: $data['iso-3166-1-code'] ?? null,
            iso_3166_2_code: $data['iso-3166-2-code'] ?? null,
            iso_3166_3_code: $data['iso-3166-3-code'] ?? null,
            annotation: $data['annotation'] ?? null,
            text: $data['text'] ?? null,
            entity: $data['entity'] ?? null,
            life_span: $data['life-span'] ?? null,
            begin: $data['begin'] ?? null,
            end: $data['end'] ?? null,
            ended: $data['ended'] ?? null,
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
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
            user_genre: $data['user-genre'] ?? null,
            language: $data['language'] ?? null,
            fluency: $data['fluency'] ?? null,
            edit_information: $data['edit-information'] ?? null,
            edits_accepted: $data['edits-accepted'] ?? null,
            edits_rejected: $data['edits-rejected'] ?? null,
            auto_edits_accepted: $data['auto-edits-accepted'] ?? null,
            edits_failed: $data['edits-failed'] ?? null
        );
    }
}