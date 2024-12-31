<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Composition\Arranger\InstrumentArranger;

use MusicBrainz\Relation\Type\Artist\Work\Composition\Arranger\InstrumentArranger;
use MusicBrainz\Value\Name;

/**
 * This indicates the person who orchestrated the work. Orchestration is a special type of arrangement. It means the adaptation of a composition for an orchestra, done in a way that the musical substance remains essentially unchanged. The orchestrator is also responsible for writing scores for an orchestra, band, choral group, individual instrumentalist(s) or vocalist(s). In practical terms it consists of deciding which instruments should play which notes in a piece of music.
 *
 * @link https://musicbrainz.org/relationship/0a1771e1-8639-4740-8a43-bdafc050c3da
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
