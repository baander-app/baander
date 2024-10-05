<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList;

use MusicBrainz\Helper\RelationFactory;

/**
 * A sorted list of relations an artist stands in
 */
class ArtistRelationList
{
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToArtistTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToEventTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToInstrumentTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToLabelTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToPlaceTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToRecordingTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToReleaseGroupTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToReleaseTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToSeriesTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToUrlTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToWorkTrait;

    /**
     * Constructs a sorted list of relations an artist stands in.
     *
     * @param array $relations Information about relations
     */
    public function __construct(array $relations = [])
    {
        $relationList = RelationFactory::makeRelations($relations);

        $this->setArtistRelationsFromArray($relationList);
        $this->setEventRelationsFromArray($relationList);
        $this->setInstrumentRelationsFromArray($relationList);
        $this->setLabelRelationsFromArray($relationList);
        $this->setPlaceRelationsFromArray($relationList);
        $this->setRecordingRelationsFromArray($relationList);
        $this->setReleaseGroupRelationsFromArray($relationList);
        $this->setReleaseRelationsFromArray($relationList);
        $this->setSeriesRelationsFromArray($relationList);
        $this->setUrlRelationsFromArray($relationList);
        $this->setWorkRelationsFromArray($relationList);
    }
}
