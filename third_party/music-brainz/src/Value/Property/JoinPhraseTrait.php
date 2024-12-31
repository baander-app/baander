<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\JoinPhrase;

use function is_null;

/**
 * Provides a getter for a joinPhrase.
 */
trait JoinPhraseTrait
{
    /**
     * The join phrase
     *
     * @var JoinPhrase
     */
    public JoinPhrase $joinPhrase;

    /**
     * Returns the joinPhrase.
     *
     * @return JoinPhrase
     */
    public function getJoinPhrase(): JoinPhrase
    {
        return $this->joinPhrase;
    }

    /**
     * Sets the join phrase by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setJoinPhraseFromArray(array $input): void
    {
        $this->joinPhrase = is_null($name = ArrayAccess::getString($input, 'joinphrase'))
            ? new JoinPhrase()
            : new JoinPhrase($name);
    }
}
