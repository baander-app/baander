<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\CollectionList;

use function is_null;

/**
 * Provides a getter for a list of collections.
 */
trait CollectionsTrait
{
    /**
     * A list of collections
     *
     * @var CollectionList
     */
    private CollectionList $collections;

    /**
     * Returns a list of collections.
     *
     * @return CollectionList
     */
    public function getCollections(): CollectionList
    {
        return $this->collections;
    }

    /**
     * Sets a list of collections by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setCollectionsFromArray(array $input): void
    {
        $this->collections = is_null($collections = ArrayAccess::getArray($input, 'collections'))
            ? new CollectionList()
            : new CollectionList($collections);
    }
}
