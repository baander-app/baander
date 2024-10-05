<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * Annotations are text fields, functioning like a miniature wiki, that can be added to any existing artists, labels,
 * recordings, releases, release groups and works.
 * Their purpose is to add information that usually doesn't fit into the strict structural data schema of MusicBrainz
 * (be it due to technical limitations that may be addressed later, or because the information in itself has to be
 * free-text).
 *
 * @see https://musicbrainz.org/doc/Annotation
 */
class Annotation implements Value
{
    use Property\AnnotationTextTrait;
    use Property\EntityTypeTrait;
    use Property\NameTrait;
    use Property\MBIDTrait;

    /**
     * Constructs an annotation.
     *
     * @param array $alias Information about the annotation
     */
    public function __construct(array $alias = [])
    {
        $this->setAnnotationTextFromArray($alias);
        $this->setEntityTypeFromArray($alias, 'type');
        $this->setMbidFromArray($alias, 'entity');
        $this->setNameFromArray($alias);
    }

    /**
     * Returns the annotation as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getName() . ': ' . $this->getAnnotationText();
    }
}
