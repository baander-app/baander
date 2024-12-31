<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\LabelType;

use function is_null;

/**
 * Provides a getter for the label type.
 */
trait LabelTypeTrait
{
    /**
     * The type describes the main activity of the label.
     *
     * @var LabelType
     */
    private LabelType $labelType;

    /**
     * Returns the label type.
     *
     * @return LabelType
     */
    public function getLabelType(): LabelType
    {
        return $this->labelType;
    }

    /**
     * Sets the label type by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLabelTypeFromArray(array $input): void
    {
        $this->labelType = is_null($labelType = ArrayAccess::getString($input, 'type'))
            ? new LabelType()
            : new LabelType($labelType);
    }
}
