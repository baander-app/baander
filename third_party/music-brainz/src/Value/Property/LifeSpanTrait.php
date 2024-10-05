<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\LifeSpan;

use function is_null;

/**
 * Provides a getter for a life span.
 */
trait LifeSpanTrait
{
    /**
     * The artist's life span
     *
     * @var LifeSpan
     */
    private LifeSpan $lifeSpan;

    /**
     * Returns the artist's life span.
     *
     * @return LifeSpan
     */
    public function getLifeSpan(): LifeSpan
    {
        return $this->lifeSpan;
    }

    /**
     * Sets the life span by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLifeSpanFromArray(array $input): void
    {
        $this->lifeSpan = is_null($lifeSpan = ArrayAccess::getArray($input, 'life-span'))
            ? new LifeSpan()
            : new LifeSpan($lifeSpan);
    }
}
