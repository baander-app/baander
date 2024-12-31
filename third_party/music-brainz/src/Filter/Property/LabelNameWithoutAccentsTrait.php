<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait LabelNameWithoutAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the label's name (without accented characters).
     *
     * @return string
     */
    public static function labelNameWithoutAccents(): string
    {
        return 'label';
    }

    /**
     * Adds the label's name (without accented characters).
     *
     * @param Name $labelNameWithoutAccents The label's name (without accented characters)
     *
     * @return Phrase
     */
    public function addLabelNameWithoutAccents(Name $labelNameWithoutAccents): Phrase
    {
        return $this->addPhrase((string) $labelNameWithoutAccents, self::labelNameWithoutAccents());
    }
}
