<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\SeriesRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations a series stands in.
 */
trait SeriesRelationListTrait
{
    /**
     * A list of relations the series stands in.
     *
     * @var SeriesRelationList
     */
    private SeriesRelationList $relations;

    /**
     * Returns the list of relations the series stands in.
     *
     * @return SeriesRelationList
     */
    public function getRelations(): SeriesRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the series stands in by extracting it from a given input array.
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
                    $this->relations = new SeriesRelationList($array);

                    return;
                }
            }

            $this->relations = new SeriesRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new SeriesRelationList($array)
            : new SeriesRelationList();
    }
}
