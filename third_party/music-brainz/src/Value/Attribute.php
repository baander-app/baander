<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An attribute
 */
class Attribute implements Value
{
    use Property\AttributeTypeTrait;
    use Property\AttributeValueTrait;

    /**
     * Constructs an attribute.
     *
     * @param array $attribute Information about the attribute
     */
    public function __construct(array $attribute = [])
    {
        $this->setAttributeTypeFromArray($attribute);
        $this->setAttributeValueFromArray($attribute);
    }

    /**
     * Returns the attribute as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getAttributeType() . ': ' . (string) $this->getAttributeValue();
    }
}
