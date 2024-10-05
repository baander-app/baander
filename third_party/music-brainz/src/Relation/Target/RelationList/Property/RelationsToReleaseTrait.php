<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationToRelease;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to a release.
 */
trait RelationsToReleaseTrait
{
    /**
     * A list of relations to a release
     *
     * @var RelationToRelease[]|RelationToReleaseList
     */
    private RelationToReleaseList $releaseRelations;

    /**
     * Returns a list of relations to a release.
     *
     * @return RelationToRelease[]|RelationToReleaseList
     */
    public function getReleaseRelations(): RelationToReleaseList
    {
        return $this->releaseRelations;
    }

    /**
     * Sets a list of relations to a release by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setReleaseRelationsFromArray(array $input): void
    {
        $this->releaseRelations = is_null($input = ArrayAccess::getArray($input, EntityType::RELEASE))
            ? new RelationToReleaseList()
            : new RelationToReleaseList($input);
    }
}
