<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use ArrayAccess;
use Countable;
use Iterator;
use MusicBrainz\Collection\ArrayAccessTrait;
use MusicBrainz\Collection\CollectionTrait;
use MusicBrainz\Collection\CountableTrait;
use MusicBrainz\Collection\IteratorTrait;

/**
 * A list of values
 *
 * @link http://php.net/manual/en/class.iterator.php
 */
abstract class ValueList implements
    ArrayAccess,
    Countable,
    Iterator
{
    use ArrayAccessTrait;
    use CollectionTrait;
    use CountableTrait;
    use IteratorTrait;

    /**
     * Constructs a list of values.
     *
     * @param array $array Array of values
     */
    public function __construct(array $array = [])
    {
        $this->elements = $array;
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    abstract public static function getType(): string;
}
