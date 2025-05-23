<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList;

use MusicBrainz\Helper\RelationFactory;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToArtistTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToEventTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToLabelTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToRecordingTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToReleaseGroupTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToReleaseTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToSeriesTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToUrlTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToWorkTrait;

/**
 * A sorted list of relations a series stands in
 */
class SeriesRelationList
{
    use RelationsToArtistTrait;
    use RelationsToEventTrait;
    use RelationsToLabelTrait;
    use RelationsToRecordingTrait;
    use RelationsToReleaseTrait;
    use RelationsToReleaseGroupTrait;
    use RelationsToSeriesTrait;
    use RelationsToUrlTrait;
    use RelationsToWorkTrait;

    /**
     * Constructs a sorted list of relations a release stands in.
     *
     * @param array $relations Information about relations
     */
    public function __construct(array $relations = [])
    {
        $relationList = RelationFactory::makeRelations($relations);

        $this->setArtistRelationsFromArray($relationList);
        $this->setEventRelationsFromArray($relationList);
        $this->setLabelRelationsFromArray($relationList);
        $this->setRecordingRelationsFromArray($relationList);
        $this->setReleaseRelationsFromArray($relationList);
        $this->setReleaseGroupRelationsFromArray($relationList);
        $this->setSeriesRelationsFromArray($relationList);
        $this->setUrlRelationsFromArray($relationList);
        $this->setWorkRelationsFromArray($relationList);
    }
}
