<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\LabelType;

trait LabelTypeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the type of label.
     *
     * @return string
     */
    public static function labelType(): string
    {
        return 'type';
    }

    /**
     * Adds the type of label.
     *
     * @param LabelType $labelType The type of label
     *
     * @return Term
     */
    public function addLabelType(LabelType $labelType): Term
    {
        return $this->addTerm((string) $labelType, self::labelType());
    }
}
