<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\CollectionName;

use function is_null;

/**
 * Provides a getter for a collection name.
 */
trait CollectionNameTrait
{
    /**
     * The collection name
     *
     * @var CollectionName
     */
    public CollectionName $collectionName;

    /**
     * Returns the collection name.
     *
     * @return CollectionName
     */
    public function getCollectionName(): CollectionName
    {
        return $this->collectionName;
    }

    /**
     * Sets the collection name by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setCollectionNameFromArray(array $input): void
    {
        $this->collectionName = is_null($collectionName = ArrayAccess::getString($input, 'name'))
            ? new CollectionName()
            : new CollectionName($collectionName);
    }
}
