<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Value\Direction;

/**
 * Provides a getter for the direction of the relation.
 */
trait DirectionTrait
{
    /**
     * The direction of the relation
     *
     * @var Direction
     */
    private Direction $direction;

    /**
     * Returns the direction of the relation.
     *
     * @return Direction
     */
    public function getDirection(): Direction
    {
        return $this->direction;
    }

    /**
     * Sets the direction of the relation.
     *
     * @param Direction $direction The direction of the relation
     *
     * @return void
     */
    private function setDirection(Direction $direction): void
    {
        $this->direction = $direction;
    }
}
