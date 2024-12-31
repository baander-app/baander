<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList;

use MusicBrainz\Helper\RelationFactory;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToAreaTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToArtistTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToEventTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToLabelTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToRecordingTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToReleaseTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToSeriesTrait;
use MusicBrainz\Relation\Target\RelationList\Property\RelationsToUrlTrait;

/**
 * A sorted list of relations a release stands in
 */
class ReleaseRelationList
{
    use RelationsToAreaTrait;
    use RelationsToArtistTrait;
    use RelationsToEventTrait;
    use RelationsToLabelTrait;
    use RelationsToRecordingTrait;
    use RelationsToReleaseTrait;
    use RelationsToSeriesTrait;
    use RelationsToUrlTrait;

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
        $this->setLabelRelationsFromArray($relationList);
        $this->setRecordingRelationsFromArray($relationList);
        $this->setReleaseRelationsFromArray($relationList);
        $this->setSeriesRelationsFromArray($relationList);
        $this->setUrlRelationsFromArray($relationList);
    }
}
