<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target;

use MusicBrainz\Relation;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\Label;

/**
 * A label relation
 */
class RelationToLabel extends Relation
{
    /**
     * The related label
     *
     * @var Label
     */
    private $label;

    /**
     * Sets the related label.
     *
     * @param array $label Information about the related label
     *
     * @return void
     */
    protected function setRelatedEntity(array $label): void
    {
        $this->label = new Label($label);
    }

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::LABEL);
    }

    /**
     * Returns the related label.
     *
     * @return Label
     */
    public function getLabel(): Label
    {
        return $this->label;
    }
}
