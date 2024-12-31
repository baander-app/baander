<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait BeginAreaNameTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the name of the beginning area.
     *
     * @return string
     */
    public static function beginAreaName(): string
    {
        return 'beginarea';
    }

    /**
     * Adds the name of the beginning area.
     *
     * @param Name $beginAreaName The name of the beginning area
     *
     * @return Phrase
     */
    public function addBeginAreaName(Name $beginAreaName): Phrase
    {
        return $this->addPhrase((string) $beginAreaName, self::beginAreaName());
    }
}
