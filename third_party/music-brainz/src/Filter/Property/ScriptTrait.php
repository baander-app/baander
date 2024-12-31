<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Script;

trait ScriptTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the script.
     *
     * @return string
     */
    public static function script(): string
    {
        return 'script';
    }

    /**
     * Adds the script.
     *
     * @param Script $script The script
     *
     * @return Term
     */
    public function addScript(Script $script): Term
    {
        return $this->addTerm((string) $script, self::script());
    }
}
