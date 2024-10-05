<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\ArtistCredit;
use MusicBrainz\Value\ArtistCreditList;

use function is_null;

/**
 * Provides a getter for a list of artist credits.
 */
trait ArtistCreditsTrait
{
    /**
     * A list of artist credits
     *
     * @var ArtistCreditList
     */
    private ArtistCreditList $artistCredits;

    /**
     * Returns a list of artist credits.
     *
     * @return ArtistCredit[]|ArtistCreditList
     */
    public function getArtistCredits(): ArtistCreditList
    {
        return $this->artistCredits;
    }

    /**
     * Sets the list of artist credits by extracting it from a given input array.
     *
     * @param array  $input An array returned by the webservice
     * @param string $key   Optional array key. Default: 'artist-credits'
     *
     * @return void
     */
    private function setArtistCreditsFromArray(array $input, string $key = 'artist-credit'): void
    {
        $this->artistCredits = is_null($artistCredits = ArrayAccess::getArray($input, $key))
            ? new ArtistCreditList()
            : new ArtistCreditList($artistCredits);
    }
}
