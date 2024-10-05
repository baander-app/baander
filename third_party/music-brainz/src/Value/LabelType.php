<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function in_array;

/**
 * The type describes the main activity of the label.
 *
 * @see https://musicbrainz.org/doc/Label/Type
 */
class LabelType implements Value
{
    /**
     * Imprint: Should be used where the label is just a logo (usually either created by a company for a specific
     * product line, or where a former company’s logo is still used on releases after the company was closed or
     * purchased, or both).
     *
     * @var string
     */
    public const IMPRINT = 'Imprint';

    /**
     * Original production: Should be used for labels producing entirely new releases.
     *
     * @var string
     */
    public const ORIGINAL_PRODUCTION = 'Original Production';

    /**
     * Bootleg production: Should be used for known bootlegs labels (as in "not sanctioned by the rights owner(s) of
     * the released work").
     *
     * @var string
     */
    public const BOOTLEG_PRODUCTION = 'Bootleg Production';

    /**
     * Reissue production: Should be used for labels specializing in catalog reissues.
     *
     * @var string
     */
    public const REISSUE_PRODUCTION = 'Reissue Production';

    /**
     * Distributor: Should be used for companies mainly distributing other labels production, often in a specific region
     * of the world.
     *
     * @var string
     */
    public const DISTRIBUTOR = 'Distributor';

    /**
     * Holding: Should be used for holdings, conglomerates or other financial entities whose main activity is not to
     * produce records, but to manage a large set of recording labels owned by them.
     *
     * @var string
     */
    public const HOLDING = 'Holding';

    /**
     * Rights society: A rights society is an organization which collects royalties on behalf of the artists. This type
     * is designed to be used with the rights society relationship type rather than as a normal release label.
     *
     * @var string
     */
    public const RIGTHS_SOCIETY = 'Rights Society';

    /**
     * An undefined label type.
     *
     * @var string
     */
    public const UNDEFINED = 'Undefined';

    /**
     * A list of valid label type codes
     *
     * @var string[]
     */
    private const TYPES = [
        self::IMPRINT,
        self::ORIGINAL_PRODUCTION,
        self::BOOTLEG_PRODUCTION,
        self::REISSUE_PRODUCTION,
        self::DISTRIBUTOR,
        self::HOLDING,
        self::RIGTHS_SOCIETY,
        self::UNDEFINED,
        ];

    /**
     * The label type code
     *
     * @var string
     */
    private $type;

    /**
     * Constructs a label type.
     *
     * @param string $type A label type code
     */
    public function __construct(string $type = self::UNDEFINED)
    {
        $this->type = (in_array($type, self::TYPES))
            ? $type
            : self::UNDEFINED;
    }

    /**
     * Returns the label type code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (self::UNDEFINED === $this->type)
            ? ''
            : $this->type;
    }
}
