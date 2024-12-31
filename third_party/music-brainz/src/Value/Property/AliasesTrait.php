<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Alias;
use MusicBrainz\Value\AliasList;

use function is_null;

/**
 * Provides a getter for a list of aliases.
 */
trait AliasesTrait
{
    /**
     * A list of aliases
     *
     * @var Alias[]|AliasList
     */
    private AliasList $aliases;

    /**
     * Returns a list of aliases.
     *
     * @return Alias[]|AliasList
     */
    public function getAliases(): AliasList
    {
        return $this->aliases;
    }

    /**
     * Sets a list of aliases by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setAliasesFromArray(array $input): void
    {
        $this->aliases = is_null($aliases = ArrayAccess::getArray($input, 'aliases'))
            ? new AliasList()
            : new AliasList($aliases);
    }
}
