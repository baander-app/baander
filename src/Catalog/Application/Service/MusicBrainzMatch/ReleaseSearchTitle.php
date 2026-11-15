<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

/**
 * The title an album search tries when the album's own title finds no release.
 *
 * Some album titles carry the label, catalogue number and country of their release, as in
 * "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]". MusicBrainz titles the album without
 * that text, and its search matches a title only as a whole phrase. Only a list is cut: other text
 * in brackets, such as "[Instrumentals]" or "(Live)", can name another release.
 */
final class ReleaseSearchTitle
{
    /** A group in square brackets that holds a comma and ends the title, with the white space before it. */
    private const string TRAILING_LIST = '/\s*\[[^\[\]]*,[^\[\]]*\]\z/u';

    private const string LETTER_OR_DIGIT = '/[\p{L}\p{N}]/u';

    /**
     * The title cut before a comma-separated list in square brackets at its end, so
     * "Album (Deluxe Edition) [Label, CAT-1, EU]" becomes "Album (Deluxe Edition)". A title that
     * ends in no such list, or that would keep no letter or digit, is returned as it is.
     */
    public static function shortened(string $title): string
    {
        $shortened = preg_replace(self::TRAILING_LIST, '', $title) ?? $title;

        return preg_match(self::LETTER_OR_DIGIT, $shortened) === 1 ? $shortened : $title;
    }
}
