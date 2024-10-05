<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Value\AttributeValue;

/**
 * Provides a getter for the attribute value.
 */
trait AttributeValueTrait
{
    /**
     * The attribute value
     *
     * @var AttributeValue
     */
    private AttributeValue $attributeValue;

    /**
     * Returns the attribute value.
     *
     * @return AttributeValue
     */
    public function getAttributeValue(): AttributeValue
    {
        return $this->attributeValue;
    }

    /**
     * Sets the attribute type from a given input array.
     * We don't need to use the array access helper class here, because we hand over the whole array to the attribute
     * value, to split it from the attribute type.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setAttributeValueFromArray(array $input): void
    {
        $this->attributeValue = new AttributeValue($input);
    }
}
