<?php

declare(strict_types=1);

namespace MusicBrainz\Definition;

use MusicBrainz\Relation;
use MusicBrainz\Relation\Target\RelationToArea;
use MusicBrainz\Relation\Target\RelationToArtist;
use MusicBrainz\Relation\Target\RelationToEvent;
use MusicBrainz\Relation\Target\RelationToInstrument;
use MusicBrainz\Relation\Target\RelationToLabel;
use MusicBrainz\Relation\Target\RelationToPlace;
use MusicBrainz\Relation\Target\RelationToRecording;
use MusicBrainz\Relation\Target\RelationToRelease;
use MusicBrainz\Relation\Target\RelationToReleaseGroup;
use MusicBrainz\Relation\Target\RelationToSeries;
use MusicBrainz\Relation\Target\RelationToUrl;
use MusicBrainz\Relation\Target\RelationToWork;
use MusicBrainz\Value\EntityType;

class RelationTarget
{
    /**
     * Returns a list of relation classes associated their target's entity type.
     *
     * @return array<string, class-string<Relation>>
     */
    public static function getClassMap(): array
    {
        return [
            EntityType::AREA          => RelationToArea::class,
            EntityType::ARTIST        => RelationToArtist::class,
            EntityType::EVENT         => RelationToEvent::class,
            EntityType::INSTRUMENT    => RelationToInstrument::class,
            EntityType::LABEL         => RelationToLabel::class,
            EntityType::PLACE         => RelationToPlace::class,
            EntityType::RECORDING     => RelationToRecording::class,
            EntityType::RELEASE       => RelationToRelease::class,
            EntityType::RELEASE_GROUP => RelationToReleaseGroup::class,
            EntityType::SERIES        => RelationToSeries::class,
            EntityType::URL           => RelationToUrl::class,
            EntityType::WORK          => RelationToWork::class,
        ];
    }
}
