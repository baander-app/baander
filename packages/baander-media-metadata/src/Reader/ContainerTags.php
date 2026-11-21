<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Reader;

use Baander\MediaMetadata\Model\ExtractedMetadata;

/** Container text fills fields that the embedded ID3 tag did not populate. */
final class ContainerTags
{
    /** @param array<string, string> $tags */
    public static function fill(ExtractedMetadata $metadata, array $tags): void
    {
        foreach ($tags as $field => $text) {
            $value = rtrim($text, "\0") |> trim(...);
            if ($value === '') {
                continue;
            }
            $value = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            $getter = 'get' . $field;
            $setter = 'set' . $field;
            if ($metadata->$getter() !== null && $metadata->$getter() !== []) {
                continue;
            }
            if (in_array($field, ['Year', 'TrackNumber', 'Bpm'], true)) {
                $number = (int) $value;
                if ($number > 0) {
                    $metadata->$setter($number);
                }
            } elseif ($field === 'Genre') {
                $metadata->setGenre([$value]);
            } else {
                $metadata->$setter($value);
            }
        }
    }
}
