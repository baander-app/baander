<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait WorkNameWithAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the name of the work.
     *
     * @return string
     */
    public static function workNameWithAccents(): string
    {
        return 'workaccent';
    }

    /**
     * Adds the name of the work.
     *
     * @param Name $workNameWithAccents The name of the work
     *
     * @return Phrase
     */
    public function addWorkNameWithAccents(Name $workNameWithAccents): Phrase
    {
        return $this->addPhrase((string) $workNameWithAccents, self::workNameWithAccents());
    }
}
