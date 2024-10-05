<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Relation\RelationList\Property\AreaRelationListTrait;
use MusicBrainz\Value;

/**
 * An area
 */
class Area implements Value
{
    use AreaRelationListTrait;
    use Property\AliasesTrait;
    use Property\AreaTypeTrait;
    use Property\DisambiguationTrait;
    use Property\ISO31661CodesTrait;
    use Property\ISO31662CodesTrait;
    use Property\LifeSpanTrait;
    use Property\MBIDTrait;
    use Property\NameTrait;
    use Property\SortNameTrait;

    /**
     * Constructs an area.
     *
     * @param array $area Information about an area
     */
    public function __construct(array $area = [])
    {
        $this->setAliasesFromArray($area);
        $this->setAreaTypeFromArray($area);
        $this->setDisambiguationFromArray($area);
        $this->setIso31661CodesFromArray($area);
        $this->setIso31662CodesFromArray($area);
        $this->setLifeSpanFromArray($area);
        $this->setMbidFromArray($area);
        $this->setNameFromArray($area);
        $this->setRelationsFromArray($area);
        $this->setSortNameFromArray($area);
    }

    /**
     * Returns the area name.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getName();
    }
}
