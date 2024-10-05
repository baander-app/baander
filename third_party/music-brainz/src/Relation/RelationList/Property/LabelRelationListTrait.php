<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\LabelRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations an label stands in.
 */
trait LabelRelationListTrait
{
    /**
     * A list of relations the label stands in.
     *
     * @var LabelRelationList
     */
    private LabelRelationList $relations;

    /**
     * Returns the list of relations the label stands in.
     *
     * @return LabelRelationList
     */
    public function getRelations(): LabelRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the label stands in by extracting it from a given input array.
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
                    $this->relations = new LabelRelationList($array);

                    return;
                }
            }

            $this->relations = new LabelRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new LabelRelationList($array)
            : new LabelRelationList();
    }
}
