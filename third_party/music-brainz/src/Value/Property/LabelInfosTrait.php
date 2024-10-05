<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\LabelInfo;
use MusicBrainz\Value\LabelInfoList;

use function is_null;

/**
 * Provides a getter for a list of label information.
 */
trait LabelInfosTrait
{
    /**
     * A list of label information
     *
     * @var LabelInfo[]|LabelInfoList
     */
    private LabelInfoList $labelInfos;

    /**
     * Returns a list of label information.
     *
     * @return LabelInfo[]|LabelInfoList
     */
    public function getLabelInfos(): LabelInfoList
    {
        return $this->labelInfos;
    }

    /**
     * Sets a list of label information by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLabelInfosFromArray(array $input): void
    {
        $this->labelInfos = is_null($labelInfos = ArrayAccess::getArray($input, 'label-info'))
            ? new LabelInfoList()
            : new LabelInfoList($labelInfos);
    }
}
