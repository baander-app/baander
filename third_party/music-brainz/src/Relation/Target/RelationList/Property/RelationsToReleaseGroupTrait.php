<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseGroupList;
use MusicBrainz\Relation\Target\RelationToReleaseGroup;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to a release group.
 */
trait RelationsToReleaseGroupTrait
{
    /**
     * A list of relations to a release group
     *
     * @var RelationToReleaseGroup[]|RelationToReleaseGroupList
     */
    private RelationToReleaseGroupList $releaseGroupRelations;

    /**
     * Returns a list of relations to a release group.
     *
     * @return RelationToReleaseGroup[]|RelationToReleaseGroupList
     */
    public function getReleaseGroupRelations(): RelationToReleaseGroupList
    {
        return $this->releaseGroupRelations;
    }

    /**
     * Sets a list of relations to a release group by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setReleaseGroupRelationsFromArray(array $input): void
    {
        $this->releaseGroupRelations = is_null($input = ArrayAccess::getArray($input, EntityType::RELEASE_GROUP))
            ? new RelationToReleaseGroupList()
            : new RelationToReleaseGroupList($input);
    }
}
