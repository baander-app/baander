<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Title;

trait TitleTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the title.
     *
     * @return string
     */
    public static function title(): string
    {
        return 'title';
    }

    /**
     * Adds the title.
     *
     * @param Title $title The title comment
     *
     * @return Phrase
     */
    public function addTitleComment(Title $title): Phrase
    {
        return $this->addPhrase((string) $title, self::title());
    }
}
