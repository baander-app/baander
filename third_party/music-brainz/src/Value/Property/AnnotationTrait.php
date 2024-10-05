<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Annotation;

use function is_null;

/**
 * Provides a getter for a annotation.
 */
trait AnnotationTrait
{
    /**
     * The annotation
     *
     * @var Annotation
     */
    private Annotation $annotation;

    /**
     * Returns the annotation.
     *
     * @return Annotation
     */
    public function getAnnotation(): Annotation
    {
        return $this->annotation;
    }

    /**
     * Sets the annotation by extracting it from a given input array.
     *
     * @param array  $annotation An array returned by the webservice
     * @param string $key        Optional array key. Default: 'annotation'
     *
     * @preturn void
     */
    private function setAnnotationFromArray(array $annotation = [], ?string $key = 'annotation'): void
    {
        $this->annotation = (is_null($key))
            ? new Annotation($annotation)
            : new Annotation(ArrayAccess::getArray($annotation, $key) ?? []);
    }
}
