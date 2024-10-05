<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value;

/**
 * A collection
 */
class Collection implements Value
{
    use Property\CollectionNameTrait;
    use Property\CountTrait;
    use Property\EditorNameTrait;
    use Property\EntityTypeTrait;
    use Property\MBIDTrait;

    /**
     * Constructs a collection.
     *
     * @param array $collection Information about the collection
     */
    public function __construct(array $collection = [])
    {
        $this->setCollectionNameFromArray($collection);
        $this->setCountFromArray($collection, ArrayAccess::getString($collection, 'entity-type') . '-count');
        $this->setEditorNameFromArray($collection);
        $this->setEntityTypeFromArray($collection);
        $this->setMbidFromArray($collection);
    }

    /**
     * Returns the collection name as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getCollectionName();
    }
}
