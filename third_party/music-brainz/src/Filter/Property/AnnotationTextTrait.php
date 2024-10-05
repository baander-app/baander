<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\AnnotationText;

trait AnnotationTextTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the annotation text.
     *
     * @return string
     */
    public static function annotationText(): string
    {
        return 'text';
    }

    /**
     * Adds an annotation text.
     *
     * @param AnnotationText $annotationText An annotation text
     *
     * @return Phrase
     */
    public function addAnnotationText(AnnotationText $annotationText): Phrase
    {
        return $this->addPhrase((string) $annotationText, self::annotationText());
    }
}
