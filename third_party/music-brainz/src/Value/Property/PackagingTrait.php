<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Packaging;

use function is_null;

/**
 * Provides a getter for a packaging.
 */
trait PackagingTrait
{
    /**
     * The packaging
     *
     * @var Packaging
     */
    private Packaging $packaging;

    /**
     * Returns the packaging.
     *
     * @return Packaging
     */
    public function getPackaging(): Packaging
    {
        return $this->packaging;
    }

    /**
     * Sets the packaging by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setPackagingFromArray(array $input): void
    {
        $packaging   = ArrayAccess::getString($input, 'packaging');
        $packagingId = ArrayAccess::getString($input, 'packaging-id');

        $this->packaging = (is_null($packaging) && is_null($packagingId))
        ? new Packaging()
        : new Packaging($packaging ?? Packaging::UNDEFINED, new MBID($packagingId ?: ''));
    }
}
