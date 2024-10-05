<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Work\Work\OtherVersion;

use MusicBrainz\Relation\Type\Work\Work\OtherVersion;
use MusicBrainz\Value\Name;

/**
 * This is used when a work includes a brief quotation of the music of another work. In most cases the quotation is uncredited, although this is not a requirement. For a work that includes significantly more of another than just a brief quotation, consider using “based on” instead.
 *
 * @link https://musicbrainz.org/relationship/c5decae0-535c-4730-aa5f-ab78eadd98ba
 */
class MusicalQuotation extends OtherVersion
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('musical quotation');
    }
}
