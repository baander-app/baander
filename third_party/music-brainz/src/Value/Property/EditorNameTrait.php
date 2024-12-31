<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\EditorName;

use function is_null;

/**
 * Provides a getter for an editor name.
 */
trait EditorNameTrait
{
    /**
     * The editor name
     *
     * @var EditorName
     */
    public EditorName $editorName;

    /**
     * Returns the editor name.
     *
     * @return EditorName
     */
    public function getEditorName(): EditorName
    {
        return $this->editorName;
    }

    /**
     * Sets the editor name by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setEditorNameFromArray(array $input): void
    {
        $this->editorName = is_null($editorName = ArrayAccess::getString($input, 'editor'))
            ? new EditorName()
            : new EditorName($editorName);
    }
}
