<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList;

use MusicBrainz\Helper\RelationFactory;

/**
 * A sorted list of relations an URL stands in
 */
class UrlRelationList
{
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToAreaTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToArtistTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToEventTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToInstrumentTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToLabelTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToPlaceTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToRecordingTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToReleaseTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToReleaseGroupTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToSeriesTrait;
    use \MusicBrainz\Relation\Target\RelationList\Property\RelationsToWorkTrait;

    /**
     * Constructs a sorted list of relations a release stands in.
     *
     * @param array $relations Information about relations
     */
    public function __construct(array $relations = [])
    {
        $relationList = RelationFactory::makeRelations($relations);

        $this->setAreaRelationsFromArray($relationList);
        $this->setArtistRelationsFromArray($relationList);
        $this->setEventRelationsFromArray($relationList);
        $this->setInstrumentRelationsFromArray($relationList);
        $this->setLabelRelationsFromArray($relationList);
        $this->setPlaceRelationsFromArray($relationList);
        $this->setRecordingRelationsFromArray($relationList);
        $this->setReleaseRelationsFromArray($relationList);
        $this->setReleaseGroupRelationsFromArray($relationList);
        $this->setSeriesRelationsFromArray($relationList);
        $this->setWorkRelationsFromArray($relationList);
    }
}
