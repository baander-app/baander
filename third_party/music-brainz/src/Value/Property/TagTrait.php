<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Tag;

use function is_null;

/**
 * Provides a getter for a Tag.
 */
trait TagTrait
{
    /**
     * The Tag number
     *
     * @var Tag
     */
    public Tag $tag;

    /**
     * Returns the Tag.
     *
     * @return Tag
     */
    public function getTag(): Tag
    {
        return $this->tag;
    }

    /**
     * Sets the tag by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setTagFromArray(array $input): void
    {
        $this->tag = is_null($tag = ArrayAccess::getArray($input, 'tag'))
            ? new Tag()
            : new Tag($tag);
    }
}
