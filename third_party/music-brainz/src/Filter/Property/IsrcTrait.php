<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ISRC;

trait IsrcTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the International Standard Recording Code (ISRC) of the recording.
     *
     * @return string
     */
    public static function isrcCode(): string
    {
        return 'isrc';
    }

    /**
     * Adds the International Standard Recording Code (ISRC) of the recording.
     *
     * @param ISRC $isrcCode The International Standard Recording Code (ISRC) of the recording
     *
     * @return Term
     */
    public function addIsrcCode(ISRC $isrcCode): Term
    {
        return $this->addTerm((string) $isrcCode, self::isrcCode());
    }
}
