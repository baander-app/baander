<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An annotation text
 */
class AnnotationText implements Value
{
    /**
     * The text
     *
     * @var string
     */
    private string $text;

    /**
     * Constructs an annotation text.
     *
     * @param string $text The text
     */
    public function __construct(string $text = '')
    {
        $this->text = $text;
    }

    /**
     * Returns the annotation text as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
