<?php

declare(strict_types=1);

namespace MusicBrainz\Collection;

/**
 * Provides an array containing elements a collection
 */
trait CollectionTrait
{
    /**
     * An array containing the elements of this collection
     *
     * @var array
     */
    protected array $elements = [];
}
