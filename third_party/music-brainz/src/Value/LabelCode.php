<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * Label Code
 * "The Label Code (LC) was introduced in 1977 by the IFPI (International Federation of Phonogram and Videogram
 * Industries) in order to unmistakably identify the different record labels (see Introduction, Record labels) for
 * rights purposes. The Label Code consists historically of 4 figures, presently being extended to 5 figures, preceded
 * by LC and a dash (e.g. LC-0193 = Electrola; LC-0233 = His Master's Voice). Note that the number of countries using
 * the LC is limited, and that the code given on the item is not always accurate."
 *
 * @link http://www.iasa-web.org/icat/08_0.htm
 * @link https://musicbrainz.org/doc/Label/Label_Code
 */
class LabelCode implements Value
{
    /**
     * The Label Code with "LC-" prefix and 5 digits
     *
     * @var string
     */
    private $code;

    /**
     * Constructs a Label Code.
     *
     * @param string $labelCode A label code
     */
    public function __construct(string $labelCode = '')
    {
        $this->code = $this->getNormalizedLabelCode($labelCode);
    }

    /**
     * Returns true, if the given string is a valid Label Code, otherwise false.
     *
     * The code
     * - must be a number between 1 and 99999
     * - may be prefixed by "LC" (case insensitive), where the prefix may be separated with a dash ("-"), or a space (" ")
     *
     * @param string $labelCode
     *
     * @return bool
     */
    private function isValidLabelCode(string $labelCode): bool
    {
        return (bool) preg_match('/^(LC)?[-| ]?(?=.*[1-9].*)[0-9]{1,5}$/i', $labelCode);
    }

    /**
     * Normalizes a given label code to the format "LC-01234" and returns the result. Returns an empty string for an
     * invalid code given.
     *
     * @param string $labelCode A Label Code
     *
     * @return string
     */
    private function getNormalizedLabelCode(string $labelCode): string
    {
        return ($this->isValidLabelCode($labelCode))
            ? 'LC-' . str_pad(
                (string) abs(filter_var($labelCode, FILTER_SANITIZE_NUMBER_INT)), // @phpstan-ignore-line
                5,
                '0',
                STR_PAD_LEFT
            )
            : '';
    }

    /**
     * Returns the label code without the "LC-" prefix.
     *
     * @return string
     */
    public function getLabelCodeWithoutLcPrefix()
    {
        return substr($this->code, 3);
    }

    /**
     * Returns the Label Code with "LC-" prefix and 5 digits.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
