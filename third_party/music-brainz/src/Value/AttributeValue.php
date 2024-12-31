<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An attribute value
 */
class AttributeValue implements Value
{
    use Property\MBIDTrait;
    use Property\NameTrait;

    /**
     * Constructs an attribute value.
     *
     * @param array $attributeValue Information about the attribute value
     */
    public function __construct(array $attributeValue = [])
    {
        $this->setMbidFromArray($attributeValue);
        $this->setNameFromArray($attributeValue);
    }

    /**
     * Returns the attribute value as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getName();
    }
}
