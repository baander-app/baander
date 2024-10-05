<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\UrlRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations an URL stands in.
 */
trait UrlRelationListTrait
{
    /**
     * A list of relations the URL stands in.
     *
     * @var UrlRelationList
     */
    private UrlRelationList $relations;

    /**
     * Returns the list of relations the URL stands in.
     *
     * @return UrlRelationList
     */
    public function getRelations(): UrlRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the URL stands in by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setRelationsFromArray(array $input): void
    {
        if (!is_null(ArrayAccess::getArray($input, 'relation-list'))) {
            if (!is_null($array = ArrayAccess::getArray($input['relation-list'], '0'))) {
                if (!is_null($array = ArrayAccess::getArray($array, 'relations'))) {
                    $this->relations = new UrlRelationList($array);

                    return;
                }
            }

            $this->relations = new UrlRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new UrlRelationList($array)
            : new UrlRelationList();
    }
}
