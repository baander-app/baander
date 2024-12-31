<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An attribute type
 */
class AttributeType implements Value
{
    use Property\MBIDTrait;
    use Property\NameTrait;

    /**
     * Constructs an attribute type.
     *
     * @param array $attributeType Information about the attribute type
     */
    public function __construct(array $attributeType = [])
    {
        $this->setMbidFromArray($attributeType, 'type-id');
        $this->setNameFromArray($attributeType, 'type');
    }

    /**
     * Returns the attribute name as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getName();
    }
}
