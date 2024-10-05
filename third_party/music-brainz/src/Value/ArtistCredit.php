<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An artist credit
 */
class ArtistCredit implements Value
{
    use Property\ArtistTrait;
    use Property\JoinPhraseTrait;
    use Property\NameTrait;

    /**
     * Constructs an artist credit.
     *
     * @param array $artistCredit An array of information about the artist credit
     */
    public function __construct(array $artistCredit = [])
    {
        $this->setArtistFromArray($artistCredit);
        $this->setJoinPhraseFromArray($artistCredit);
        $this->setNameFromArray($artistCredit);
    }

    /**
     * Returns the artist credit as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        $joinPhrase = (empty((string) $this->getJoinPhrase())) ? '' : $this->getJoinPhrase() . ' ';

        return $joinPhrase . $this->getName();
    }
}
