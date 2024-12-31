<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\CdStub;

use function is_null;

/**
 * Provides a getter for the CD stub.
 */
trait CdStubTrait
{
    /**
     * The CD stub
     *
     * @var CdStub
     */
    private CdStub $cdStub;

    /**
     * Returns the CD stub.
     *
     * @return CdStub
     */
    public function getCdStub(): CdStub
    {
        return $this->cdStub;
    }

    /**
     * Sets the CdStub by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setCdStubFromArray(array $input): void
    {
        $this->cdStub = is_null($cdStub = ArrayAccess::getArray($input, 'cdStub'))
            ? new CdStub()
            : new CdStub($cdStub);
    }
}
