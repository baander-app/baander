<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A tag
 */
class Tag implements Value
{
    use Value\Property\CountTrait;
    use Value\Property\NameTrait;

    /**
     * Constructs a tag.
     *
     * @param array $tag Array of information about the tag
     */
    public function __construct(array $tag = [])
    {
        $this->setCountFromArray($tag);
        $this->setNameFromArray($tag);
    }

    /**
     * Returns the tag.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->name;
    }
}
