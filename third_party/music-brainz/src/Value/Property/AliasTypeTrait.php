<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\AliasType;
use MusicBrainz\Value\MBID;

/**
 * Provides a getter for the alias type.
 */
trait AliasTypeTrait
{
    /**
     * An alias type
     *
     * @var AliasType
     */
    private AliasType $aliasType;

    /**
     * Returns the alias type.
     *
     * @return AliasType
     */
    public function getAliasType(): AliasType
    {
        return $this->aliasType;
    }

    /**
     * Sets the alias type by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setAliasTypeFromArray(array $input): void
    {
        $this->aliasType = new AliasType(
            ArrayAccess::getString($input, 'type'),
            new MBID(ArrayAccess::getString($input, 'type-id'))
        );
    }
}
