<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\LabelCode;

trait LabelCodeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the Label Code (LC).
     *
     * @return string
     */
    public static function labelCode(): string
    {
        return 'code';
    }

    /**
     * Adds the Label Code (LC) of the label.
     *
     * @param LabelCode $labelCode The Label Code (LC) of the label
     *
     * @return Term
     */
    public function addLabelCode(LabelCode $labelCode): Term
    {
        return $this->addTerm($labelCode->getLabelCodeWithoutLcPrefix(), self::labelCode());
    }
}
