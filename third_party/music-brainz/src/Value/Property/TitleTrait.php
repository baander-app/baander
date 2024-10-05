<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Title;

use function is_null;

/**
 * Provides a getter for a title.
 */
trait TitleTrait
{
    /**
     * The title
     *
     * @var Title
     */
    public Title $title;

    /**
     * Returns the title.
     *
     * @return Title
     */
    public function getTitle(): Title
    {
        return $this->title;
    }

    /**
     * Sets the title by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setTitleFromArray(array $input): void
    {
        $this->title = is_null($title = ArrayAccess::getString($input, 'title'))
            ? new Title()
            : new Title($title);
    }
}
