<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\LabelCode;

use function is_null;

/**
 * Provides a getter for the label code.
 */
trait LabelCodeTrait
{
    /**
     * The label code
     *
     * @var LabelCode
     */
    private LabelCode $labelCode;

    /**
     * Returns the label code.
     *
     * @return LabelCode
     */
    public function getLabelCode(): LabelCode
    {
        return $this->labelCode;
    }

    /**
     * Sets the label code by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLabelCodeFromArray(array $input): void
    {
        $this->labelCode = is_null($labelCode = ArrayAccess::getString($input, 'label-code'))
            ? new LabelCode()
            : new LabelCode($labelCode);
    }
}
