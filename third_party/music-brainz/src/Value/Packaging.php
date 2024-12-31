<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function in_array;

/**
 * A packaging
 *
 * @link https://musicbrainz.org/doc/Release/Packaging
 */
class Packaging implements Value
{
    /**
     * Jewel Case
     * The traditional CD case, made of hard, brittle plastic.
     *
     * @var string
     */
    public const JEWEL_CASE = 'Jewel Case';

    /**
     * Super Jewel Box/Case
     * Similar to the regular jewel case, but with rounded corners and a latch closing mechanism.
     *
     * @var string
     */
    public const SUPER_JEWEL_CASE = 'Super Jewel Box/Case';

    /**
     * Slim Jewel Case
     * A thinner jewel case, commonly used for CD singles.
     *
     * @var string
     */
    public const SLIM_JEWEL_CASE = 'Slim Jewel Case';

    /**
     * Digipak
     * A folded cardboard outer, typically made of coated cardboard, with a plastic tray glued into it.
     *
     * @var string
     */
    public const DIGIPAK = 'Digipak';

    /**
     * Cardboard/Paper Sleeve
     * A sleeve, made of paper or cardboard. Traditional packaging for records, also seen with CDs.
     *
     * @var string
     */
    public const CARDBOARD = 'Cardboard/Paper Sleeve';

    /**
     * Keep Case
     * The traditional DVD case, made of soft plastic (usually) dark grey with a thin transparent plastic cover
     * protecting the artwork.
     *
     * @var string
     */
    public const KEEP_CASE = 'Keep Case';

    /**
     * Cassette Case
     * Regular plastic case as for a cassette.
     *
     * @var string
     */
    public const CASSETTE_CASE = 'Cassette Case';

    /**
     * Book
     * A book with a sleeve containing a medium (usually a CD).
     *
     * @var string
     */
    public const BOOK = 'Book';

    /**
     * Fatbox
     * A double-sided, double-width jewel case capable of holding up to 4 CDs.
     *
     * @var string
     */
    public const FATBOX = 'Fatbox';

    /**
     * Snap Case
     * A digipak-like case held together with a "snapping" plastic closure.
     *
     * @var string
     */
    public const SNAP_CASE = 'Snap Case';

    /**
     * Gatefold Cover
     * A cardboard sleeve that folds in halves, thirds, etc. It can hold multiple records or CDs as well as booklets,
     * posters and other memorabilia.
     *
     * @var string
     */
    public const GATEFOLD_COVER = 'Gatefold Cover';

    /**
     * Discbox Slider
     * A pouch-like package with an internal mechanism that pushes the contents (usually a CD) out of the case when the
     * lid flap is opened.
     *
     * @var string
     */
    public const DISCBOX_SLIDER = 'Discbox Slider';

    /**
     * None
     * No packaging at all. Common for digital media (downloads).
     *
     * @var string
     */
    public const NONE = 'None';

    /**
     * Undefined packaging
     *
     * @var string
     */
    public const UNDEFINED = '';

    /**
     * A list of valid packaging codes
     *
     * @var string[]
     */
    public const PACKAGINGS = [
        self::JEWEL_CASE,
        self::SUPER_JEWEL_CASE,
        self::SLIM_JEWEL_CASE,
        self::DIGIPAK,
        self::CARDBOARD,
        self::KEEP_CASE,
        self::CASSETTE_CASE,
        self::BOOK,
        self::FATBOX,
        self::SNAP_CASE,
        self::GATEFOLD_COVER,
        self::DISCBOX_SLIDER,
        self::NONE,
    ];

    /**
     * The packaging code
     *
     * @var string
     */
    private $packagingCode;

    /**
     * The Music Brainz Identifier (MBID) of the packaging
     *
     * @var MBID
     */
    private $MBID;

    /**
     * Constructs a packaging.
     *
     * @param string    $packagingCode The packaging code
     * @param null|MBID $packagingId   The Music Brainz Identifier (MBID) of the packaging
     */
    public function __construct(string $packagingCode = self::UNDEFINED, ?MBID $packagingId = null)
    {
        $this->packagingCode = in_array($packagingCode, self::PACKAGINGS)
            ? $packagingCode
            : self::UNDEFINED;

        $this->MBID = $packagingId ?: new MBID();
    }

    /**
     * Returns the Music Brainz Identifier (MBID) of the packaging.
     *
     * @return MBID
     */
    public function getMBID(): MBID
    {
        return $this->MBID;
    }

    /**
     * Returns the packaging code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (self::UNDEFINED === $this->packagingCode)
            ? ''
            : $this->packagingCode;
    }
}
