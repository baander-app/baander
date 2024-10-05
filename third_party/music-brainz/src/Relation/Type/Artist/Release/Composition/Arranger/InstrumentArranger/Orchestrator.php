<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Composition\Arranger\InstrumentArranger;

use MusicBrainz\Relation\Type\Artist\Release\Composition\Arranger\InstrumentArranger;
use MusicBrainz\Value\Name;

/**
 * This indicates the person who orchestrated the release. Orchestration is a special type of arrangement. It means the adaptation of a composition for an orchestra, done in a way that the musical substance remains essentially unchanged. The orchestrator is also responsible for writing scores for an orchestra, band, choral group, individual instrumentalist(s) or vocalist(s). In practical terms it consists of deciding which instruments should play which notes in a piece of music.
 *
 * @link https://musicbrainz.org/relationship/04e1f0b6-ef40-4168-ba25-42a87729fe09
 */
class Orchestrator extends InstrumentArranger
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('orchestrator');
    }
}
