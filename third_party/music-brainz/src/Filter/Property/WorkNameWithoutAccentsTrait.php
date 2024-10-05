<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait WorkNameWithoutAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the name of the work.
     *
     * @return string
     */
    public static function workNameWithoutAccents(): string
    {
        return 'name';
    }

    /**
     * Adds the name of the work.
     *
     * @param Name $workNameWithoutAccents The name of the work
     *
     * @return Phrase
     */
    public function addWorkNameWithoutAccents(Name $workNameWithoutAccents): Phrase
    {
        return $this->addPhrase((string) $workNameWithoutAccents, self::workNameWithoutAccents());
    }
}
