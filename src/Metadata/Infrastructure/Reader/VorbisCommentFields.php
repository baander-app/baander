<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

/**
 * Reads fields from parsed Vorbis comments, which FLAC and Ogg files share: each upper-case field
 * name maps to its values in file order, and a field may repeat.
 */
trait VorbisCommentFields
{
    /**
     * @param array<string, list<string>> $comments
     */
    private function first(array $comments, string $field): ?string
    {
        return $this->values($comments, $field)[0] ?? null;
    }

    /**
     * Every value of a field in file order, trimmed, without empty values. A byte sequence that is
     * not UTF-8 is replaced, as PostgreSQL refuses text that is not UTF-8.
     *
     * @param array<string, list<string>> $comments
     *
     * @return list<string>
     */
    private function values(array $comments, string $field): array
    {
        $values = array_map(
            static fn (string $value): string => mb_scrub($value, 'UTF-8') |> trim(...),
            $comments[strtoupper($field)] ?? [],
        );

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }
}
