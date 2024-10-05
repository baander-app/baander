<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Relation\RelationList\Property\LabelRelationListTrait;
use MusicBrainz\Value;

/**
 * A label
 */
class Label implements Value
{
    use LabelRelationListTrait;
    use Property\AliasesTrait;
    use Property\AreaTrait;
    use Property\CountryTrait;
    use Property\DisambiguationTrait;
    use Property\IpisTrait;
    use Property\IsnisTrait;
    use Property\LabelCodeTrait;
    use Property\LabelTypeTrait;
    use Property\LifeSpanTrait;
    use Property\MBIDTrait;
    use Property\NameTrait;
    use Property\ScoreTrait;
    use Property\SortNameTrait;

    /**
     * Constructs a label.
     *
     * @param array $label Array of information about a label
     */
    public function __construct(array $label = [])
    {
        $this->setRelationsFromArray($label);
        $this->setAliasesFromArray($label);
        $this->setAreaFromArray($label);
        $this->setCountryFromArray($label);
        $this->setDisambiguationFromArray($label);
        $this->setIpisFromArray($label);
        $this->setIsnisFromArray($label);
        $this->setLabelCodeFromArray($label);
        $this->setLabelTypeFromArray($label);
        $this->setLifeSpanFromArray($label);
        $this->setMbidFromArray($label);
        $this->setNameFromArray($label);
        $this->setScoreFromArray($label);
        $this->setSortNameFromArray($label);
    }

    /**
     * Returns the label as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getName();
    }
}
