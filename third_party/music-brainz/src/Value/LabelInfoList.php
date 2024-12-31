<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of label information
 */
class LabelInfoList extends ValueList
{
    /**
     * Constructs a list of label information.
     *
     * @param array $labelInfos An array containing information about the label information
     */
    public function __construct(array $labelInfos = [])
    {
        parent::__construct(
            array_map(
                function ($labelInfo) {
                    return new LabelInfo($labelInfo);
                },
                $labelInfos
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
        return LabelInfo::class;
    }
}
