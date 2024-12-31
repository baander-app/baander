<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target;

use MusicBrainz\Relation;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\Series;

/**
 * A series relation
 */
class RelationToSeries extends Relation
{
    /**
     * The related series
     *
     * @var Series
     */
    private $series;

    /**
     * Sets the related series.
     *
     * @param array $series Information about the related series
     *
     * @return void
     */
    protected function setRelatedEntity(array $series): void
    {
        $this->series = new Series($series);
    }

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::SERIES);
    }

    /**
     * Returns the related series.
     *
     * @return Series
     */
    public function getSeries(): Series
    {
        return $this->series;
    }
}
