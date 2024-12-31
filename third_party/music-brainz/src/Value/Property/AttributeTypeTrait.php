<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Value\AttributeType;
use MusicBrainz\Value\AttributeValue;

/**
 * Provides a getter for the attribute type.
 */
trait AttributeTypeTrait
{
    /**
     * The attribute type
     *
     * @var AttributeType
     */
    private AttributeType $attributeType;

    /**
     * Returns the attribute type.
     *
     * @return AttributeType
     */
    public function getAttributeType(): AttributeType
    {
        return $this->attributeType;
    }

    /**
     * Sets the attribute type from a given input array.
     * We don't need to use the array access helper class here, because we hand over the whole array to the attribute
     * type, to split it from the attribute value.
     *
     * @see AttributeValue
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setAttributeTypeFromArray(array $input): void
    {
        $this->attributeType = new AttributeType($input);
    }
}
