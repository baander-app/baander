<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\IPI;

trait IpiCodeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the IPI (interested party information) code.
     *
     * @return string
     */
    public static function ipiCode(): string
    {
        return 'ipi';
    }

    /**
     * Adds an IPI (interested party information) code.
     *
     * @param IPI $ipiCode An IPI (interested party information) code
     *
     * @return Term
     */
    public function addIpiCode(IPI $ipiCode): Term
    {
        return $this->addTerm((string) $ipiCode, self::ipiCode());
    }
}
