<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of labels
 */
class LabelList extends ValueList
{
    /**
     * Constructs a list of labels.
     *
     * @param array $labels An array containing information about the labels
     */
    public function __construct(array $labels = [])
    {
        parent::__construct(
            array_map(
                function ($label) {
                    return new Label($label);
                },
                $labels
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
        return Label::class;
    }
}
