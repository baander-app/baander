<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Cancelled;

use function is_null;

/**
 * Provides a getter for the "cancelled" flag.
 */
trait CancelledTrait
{
    /**
     * A "cancelled" flag
     *
     * @var Cancelled
     */
    private Cancelled $cancelled;

    /**
     * Returns the "cancelled" flag
     *
     * @return Cancelled
     */
    public function isCancelled(): Cancelled
    {
        return $this->cancelled;
    }

    /**
     * Sets the "cancelled" flag by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setCancelledFromArray(array $input): void
    {
        $this->cancelled = is_null($cancelled = ArrayAccess::getBool($input, 'cancelled'))
            ? new Cancelled()
            : new Cancelled($cancelled);
    }
}
