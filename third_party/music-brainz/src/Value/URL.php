<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Relation\RelationList\Property\UrlRelationListTrait;
use MusicBrainz\Value;

/**
 * An URL
 * A URL in MusicBrainz is a specific entity representing a regular internet Uniform Resource Locator. A MusicBrainz URL
 * entity can be edited to change the underlying internet URL it points to; and can be linked to Artists, Releases,
 * Tracks and Labels.
 *
 * @link https://musicbrainz.org/doc/URL
 */
class URL implements Value
{
    use Property\MBIDTrait;
    use Property\ResourceTrait;
    use UrlRelationListTrait;

    /**
     * Constructs an URL.
     *
     * @param array $resource Information about the track
     */
    public function __construct(array $resource = [])
    {
        $this->setMbidFromArray($resource);
        $this->setResourceFromArray($resource);
        $this->setRelationsFromArray($resource);
    }

    /**
     * Returns the URL resource as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getResource();
    }
}
