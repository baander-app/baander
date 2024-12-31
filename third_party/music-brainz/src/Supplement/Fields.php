<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement;

use JsonSerializable;

abstract class Fields implements JsonSerializable
{
    public function __toString(): string
    {
        return implode('+', array_keys(array_filter($this->jsonSerialize())));
    }

    public function jsonSerialize(): array
    {
        foreach (get_object_vars($this) as $key => $property) {
            $mappedKey = str_replace(
                'relations',
                'rels',
                // @phpstan-ignore-next-line The following Regex converts 'urlRelations' to 'url-relations'
                strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $key))
            );
            $array[$mappedKey] = $property;
        }

        return $array ?? [];
    }
}
