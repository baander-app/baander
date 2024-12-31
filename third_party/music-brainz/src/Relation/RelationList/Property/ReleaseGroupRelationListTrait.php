<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\ReleaseGroupRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations a release group stands in.
 */
trait ReleaseGroupRelationListTrait
{
    /**
     * A list of relations the release group stands in.
     *
     * @var ReleaseGroupRelationList
     */
    private ReleaseGroupRelationList $relations;

    /**
     * Returns the list of relations the release group stands in.
     *
     * @return ReleaseGroupRelationList
     */
    public function getRelations(): ReleaseGroupRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the release group stands in by extracting it from a given input array.
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
                    $this->relations = new ReleaseGroupRelationList($array);

                    return;
                }
            }

            $this->relations = new ReleaseGroupRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new ReleaseGroupRelationList($array)
            : new ReleaseGroupRelationList();
    }
}
