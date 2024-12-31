<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target;

use MusicBrainz\Relation;
use MusicBrainz\Value\Area;
use MusicBrainz\Value\EntityType;

/**
 * An area relation
 */
class RelationToArea extends Relation
{
    /**
     * The related area
     *
     * @var Area
     */
    private $area;

    /**
     * Sets the related area.
     *
     * @param array $area Information about the related area
     *
     * @return void
     */
    protected function setRelatedEntity(array $area): void
    {
        $this->area = new Area($area);
    }

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::AREA);
    }

    /**
     * Returns the related area.
     *
     * @return Area
     */
    public function getArea(): Area
    {
        return $this->area;
    }
}
