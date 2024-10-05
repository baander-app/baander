<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Address;

trait AddressTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the address.
     *
     * @return string
     */
    public static function address(): string
    {
        return 'address';
    }

    /**
     * Adds the address.
     *
     * @param Address $address The Address
     *
     * @return Term
     */
    public function addAddress(Address $address): Term
    {
        return $this->addTerm((string) $address, self::address());
    }
}
