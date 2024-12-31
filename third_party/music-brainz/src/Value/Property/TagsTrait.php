<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Tag;
use MusicBrainz\Value\TagList;

use function is_null;

/**
 * Provides a getter for a list of tags.
 */
trait TagsTrait
{
    /**
     * A list of tags associated to the artist
     *
     * @var Tag[]|TagList
     */
    private TagList $tags;

    /**
     * Returns a list of tags associated to the artist.
     *
     * @return Tag[]|TagList
     */
    public function getTags(): TagList
    {
        return $this->tags;
    }

    /**
     * Sets a list of tags by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setTagsFromArray(array $input): void
    {
        $this->tags = is_null($tags = ArrayAccess::getArray($input, 'tags'))
            ? new TagList()
            : new TagList($tags);
    }
}
