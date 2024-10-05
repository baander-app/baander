<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\LabelInfo;

use function is_null;

/**
 * Provides a getter for label information
 */
trait LabelInfoTrait
{
    /**
     * Label information
     *
     * @var LabelInfo
     */
    private LabelInfo $labelInfo;

    /**
     * Returns the label information.
     *
     * @return LabelInfo
     */
    public function getLabelInfo(): LabelInfo
    {
        return $this->labelInfo;
    }

    /**
     * Sets the label information by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLabelInfoFromArray(array $input): void
    {
        $this->labelInfo = is_null($label = ArrayAccess::getArray($input, 'label-info'))
            ? new LabelInfo()
            : new LabelInfo($label);
    }
}
