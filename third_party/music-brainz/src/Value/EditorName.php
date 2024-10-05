<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An editor name
 */
class EditorName implements Value
{
    /**
     * The editor name
     *
     * @var string
     */
    private $name;

    /**
     * Constructs an editor name.
     *
     * @param string $name The name
     */
    public function __construct(string $name = '')
    {
        $this->name = $name;
    }

    /**
     * Returns the editor name as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
