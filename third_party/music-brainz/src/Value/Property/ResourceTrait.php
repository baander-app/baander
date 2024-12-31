<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Resource;

use function is_null;

/**
 * Provides a getter for the resource.
 */
trait ResourceTrait
{
    /**
     * The resource
     *
     * @var Resource
     */
    private Resource $resource;

    /**
     * Returns the resource.
     *
     * @return Resource
     */
    public function getResource(): Resource
    {
        return $this->resource;
    }

    /**
     * Sets the recource by extracting it from a given input array.
     *
     * @param array  $input An array returned by the webservice
     *
     * @return void
     */
    private function setResourceFromArray(array $input): void
    {
        $this->resource = is_null($resource = ArrayAccess::getString($input, 'resource'))
            ? new Resource()
            : new Resource($resource);
    }
}
