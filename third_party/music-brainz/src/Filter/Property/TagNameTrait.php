<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait TagNameTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the tag name.
     *
     * @return string
     */
    public static function tagName(): string
    {
        return 'tag';
    }

    /**
     * Adds a tag name.
     *
     * @param Name $tagName The tag name
     *
     * @return Phrase
     */
    public function addTagName(Name $tagName): Phrase
    {
        return $this->addPhrase((string) $tagName, self::tagName());
    }
}
