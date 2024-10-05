<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait LabelNameWithAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the label's name (with accented characters).
     *
     * @return string
     */
    public static function labelNameWithAccents(): string
    {
        return 'label';
    }

    /**
     * Adds the label's name (with accented characters).
     *
     * @param Name $labelNameWithAccents The label's name (with accented characters)
     *
     * @return Phrase
     */
    public function addLabelNameWithAccents(Name $labelNameWithAccents): Phrase
    {
        return $this->addPhrase((string) $labelNameWithAccents, self::labelNameWithAccents());
    }
}
