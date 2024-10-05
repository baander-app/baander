<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait AnnotationTrait
{
    /**
     * True, if annotation should be included, otherwise false
     *
     * @var bool
     */
    protected bool $annotation = false;

    /**
     * Returns true, if annotation should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForAnnotation(): bool
    {
        return $this->annotation;
    }

    /**
     * Sets whether annotation should be included.
     *
     * @param bool $annotation True, if annotation should be included, otherwise false
     *
     * @return self
     */
    public function includeAnnotation(bool $annotation = true): self
    {
        $this->annotation = $annotation;

        return $this;
    }
}
