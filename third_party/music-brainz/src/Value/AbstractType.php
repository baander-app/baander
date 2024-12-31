<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * Provides base functionality for types.
 */
abstract class AbstractType implements Value
{
    /**
     * Code for an undefined type
     *
     * @var string
     */
    public const UNDEFINED = '';

    /**
     * The type code
     *
     * @var string
     */
    protected string $typeCode;

    /**
     * The MusicBrainz Identifier (MBID) of the alias type
     *
     * @var MBID
     */
    private MBID $mbid;

    /**
     * Constructs an alias type.
     *
     * @param null|string $typeCode An alias type code
     * @param null|MBID   $mbid
     */
    public function __construct(?string $typeCode = self::UNDEFINED, MBID $mbid = null)
    {
        $this->typeCode = $typeCode ?: self::UNDEFINED;
        $this->mbid     = $mbid ?: new MBID();
    }

    /**
     * Returns the MusicBrainz Identifier (MBID) of the instument type.
     *
     * @return MBID
     */
    public function getMbid(): MBID
    {
        return $this->mbid;
    }

    /**
     * Returns the alias type code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->typeCode;
    }
}
