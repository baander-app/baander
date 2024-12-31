<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Title;

trait TitleTrait
{
    use AbstractAdderTrait;

    /**
     * Adds the title.
     *
     * @param Title $title The title comment
     *
     * @return Phrase
     */
    public function addTitleComment(Title $title): Phrase
    {
        return $this->addPhrase((string)$title, self::title());
    }

    /**
     * Returns the field name for the title.
     *
     * @return string
     */
    public static function title(): string
    {
        return 'title';
    }
}
