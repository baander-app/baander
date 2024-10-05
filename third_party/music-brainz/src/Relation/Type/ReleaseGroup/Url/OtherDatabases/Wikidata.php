<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;

use MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the Wikidata page for this release group, and will be used to fetch Wikipedia summaries.
 *
 * @link https://musicbrainz.org/relationship/b988d08c-5d86-4a57-9557-c83b399e3580
 */
class Wikidata extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('wikidata');
    }
}
