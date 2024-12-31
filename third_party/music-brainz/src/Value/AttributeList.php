<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of attributes
 */
class AttributeList extends ValueList
{
    /**
     * Constructs a list of attributes.
     *
     * @param array $attributes Information about the attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct(
            array_map(
                function ($attribute) {
                    return new Attribute($attribute);
                },
                $attributes
            )
        );
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Attribute::class;
    }
}
