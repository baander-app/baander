<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\ReleaseRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations a release stands in.
 */
trait ReleaseRelationListTrait
{
    /**
     * A list of relations the release stands in.
     *
     * @var ReleaseRelationList
     */
    private ReleaseRelationList $relations;

    /**
     * Returns the list of relations the release stands in.
     *
     * @return ReleaseRelationList
     */
    public function getRelations(): ReleaseRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the release stands in by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setRelationsFromArray(array $input): void
    {
        if (!is_null(ArrayAccess::getArray($input, 'relation-list'))) {
            if (!is_null($array = ArrayAccess::getArray($input['relation-list'], 0))) {
                if (!is_null($array = ArrayAccess::getArray($array, 'relations'))) {
                    $this->relations = new ReleaseRelationList($array);

                    return;
                }
            }

            $this->relations = new ReleaseRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new ReleaseRelationList($array)
            : new ReleaseRelationList();
    }
}
