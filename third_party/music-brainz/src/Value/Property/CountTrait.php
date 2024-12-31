<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Count;

use function is_null;

/**
 * Provides a getter for a count.
 */
trait CountTrait
{
    /**
     * The offset
     *
     * @var Count
     */
    private Count $count;

    /**
     * Returns the count.
     *
     * @return Count
     */
    public function getCount(): Count
    {
        return $this->count;
    }

    /**
     * Sets the count by extracting it from a given input array.
     *
     * @param array  $input An array returned by the webservice
     * @param string $key   Optional key (default: 'count')
     *
     * @return void
     */
    private function setCountFromArray(array $input, string $key = 'count'): void
    {
        $this->count = is_null($count = ArrayAccess::getInteger($input, $key))
            ? new Count()
            : new Count($count);
    }

    /**
     * Sets the count.
     *
     * @param int $count The count
     */
    private function setCount(int $count = 0): void
    {
        $this->count = new Count($count);
    }
}
