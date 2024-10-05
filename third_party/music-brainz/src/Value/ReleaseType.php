<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function in_array;

/**
 * A release type
 */
class ReleaseType implements Value
{
    /**
     * An album
     *
     * @var string
     */
    public const ALBUM = 'Album';

    /**
     * An audiobook
     *
     * @var string
     */
    public const AUDIOBOOK = 'Audiobook';

    /**
     * A compilation
     *
     * @var string
     */
    public const COMPILATION = 'Compilation';

    /**
     * An EP
     *
     * @var string
     */
    public const EP = 'Ep';

    /**
     * An interview
     *
     * @var string
     */
    public const INTERVIEW = 'Interview';

    /**
     * A live recording
     *
     * @var string
     */
    public const LIVE = 'Live';

    /**
     * ?
     *
     * @var string
     */
    public const NAT = 'Nat';

    /**
     * Remixes
     *
     * @var string
     */
    public const REMIX = 'Remix';

    /**
     * A single
     *
     * @var string
     */
    public const SINGLE = 'Single';

    /**
     * A soundtrack
     *
     * @var string
     */
    public const SOUNDTRACK = 'Soundtrack';

    /**
     * Spoken words
     *
     * @var string
     */
    public const SPOKENWORD = 'Spokenword';

    /**
     * Another type
     *
     * @var string
     */
    public const OTHER = 'Other';

    /**
     * A list of valid type codes
     *
     * @var string[]
     */
    public const TYPE_CODES = [
        self::ALBUM,
        self::AUDIOBOOK,
        self::COMPILATION,
        self::EP,
        self::INTERVIEW,
        self::LIVE,
        self::NAT,
        self::REMIX,
        self::SINGLE,
        self::SOUNDTRACK,
        self::SPOKENWORD,
    ];

    /**
     * The release type code
     *
     * @var string
     */
    private $typeCode;

    /**
     * Constructs a release type.
     *
     * @param string $typeCode A release type code
     */
    public function __construct(string $typeCode = self::OTHER)
    {
        $this->typeCode = (in_array($typeCode, self::TYPE_CODES))
            ? $typeCode
            : self::OTHER;
    }

    /**
     * Returns the type code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->typeCode;
    }
}
