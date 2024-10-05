<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Disambiguation;

use function is_null;

/**
 * Provides a getter for the disambiguation comment.
 */
trait DisambiguationTrait
{
    /**
     * A disambiguation comment
     *
     * @var Disambiguation
     */
    private Disambiguation $disambiguation;

    /**
     * Returns a disambiguation comment.
     *
     * @return Disambiguation
     */
    public function getDisambiguation(): Disambiguation
    {
        return $this->disambiguation;
    }

    /**
     * Sets the disambiguation by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setDisambiguationFromArray(array $input): void
    {
        $this->disambiguation = is_null($disambiguation = ArrayAccess::getString($input, 'disambiguation'))
            ? new Disambiguation()
            : new Disambiguation($disambiguation);
    }
}
