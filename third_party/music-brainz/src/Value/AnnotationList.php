<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of annotations
 */
class AnnotationList extends ValueList
{
    /**
     * Constructs a list of annotations.
     *
     * @param array $annotations An array containing information about the annotation
     */
    public function __construct(array $annotations = [])
    {
        parent::__construct(
            array_map(
                function ($annotation) {
                    return new Annotation($annotation);
                },
                $annotations
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
        return Annotation::class;
    }
}
