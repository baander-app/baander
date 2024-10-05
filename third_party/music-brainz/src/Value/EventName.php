<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An event name
 */
class EventName implements Value
{
    /**
     * The event name
     *
     * @var string
     */
    private $name;

    /**
     * Constructs an event name.
     *
     * @param string $name The name
     */
    public function __construct(string $name = '')
    {
        $this->name = $name;
    }

    /**
     * Returns the event name as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
