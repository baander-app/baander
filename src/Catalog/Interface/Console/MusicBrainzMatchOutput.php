<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Shared\Interface\Console\AdminCommandSupport;

/** How the MusicBrainz match commands print candidates and comparisons in their tables. */
final class MusicBrainzMatchOutput
{
    /** What a candidate of the type is in MusicBrainz: a release, recording or artist. */
    public static function candidateNoun(MatchItemType $type): string
    {
        return match ($type) {
            MatchItemType::Album => 'release',
            MatchItemType::Song => 'recording',
            MatchItemType::Artist => 'artist',
        };
    }

    /** A table cell: "-" for no value, yes or no for a flag, lists joined by commas. */
    public static function cell(mixed $value): string
    {
        return match (true) {
            $value === null, $value === [], $value === '' => '-',
            is_bool($value) => AdminCommandSupport::yesNo($value),
            is_array($value) => implode(', ', array_map(self::cell(...), $value)),
            is_scalar($value) => (string) $value,
            default => '-',
        };
    }

    /** A length in seconds as m:ss; "-" when unknown. */
    public static function seconds(mixed $seconds): string
    {
        if (!is_int($seconds) && !is_float($seconds)) {
            return '-';
        }

        $whole = (int) round($seconds);

        return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }

    /** A length in milliseconds as m:ss; "-" when unknown. */
    public static function milliseconds(mixed $milliseconds): string
    {
        return is_int($milliseconds) ? self::seconds($milliseconds / 1000) : '-';
    }

    /** The text with MusicBrainz's comment that tells it apart, such as `F-1 Trillion (clean)`. */
    public static function withDisambiguation(string $text, ?string $disambiguation): string
    {
        return $disambiguation === null || $disambiguation === '' ? $text : sprintf('%s (%s)', $text, $disambiguation);
    }
}
