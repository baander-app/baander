<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A script
 */
class Script implements Value
{
    /**
     * The script code
     *
     * @var string
     */
    private $scriptCode;

    /**
     * Constructs a script.
     *
     * @param string $scriptCode The script code
     */
    public function __construct(string $scriptCode = '')
    {
        $this->scriptCode = $scriptCode;
    }

    /**
     * Returns the string code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->scriptCode;
    }
}
