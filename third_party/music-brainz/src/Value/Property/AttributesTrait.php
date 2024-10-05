<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Attribute;
use MusicBrainz\Value\AttributeList;

use function is_null;

/**
 * Provides a getter for a list of attributes.
 */
trait AttributesTrait
{
    /**
     * A list of attributes
     *
     * @var Attribute[]|AttributeList
     */
    private AttributeList $attributes;

    /**
     * Returns a list of attributes.
     *
     * @return Attribute[]|AttributeList
     */
    public function getAttributes(): AttributeList
    {
        return $this->attributes;
    }

    /**
     * Sets the list of attributes by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setAttributesFromArray(array $input): void
    {
        $this->attributes = is_null($attributes = ArrayAccess::getArray($input, 'attributes'))
            ? new AttributeList()
            : new AttributeList($attributes);
    }
}
