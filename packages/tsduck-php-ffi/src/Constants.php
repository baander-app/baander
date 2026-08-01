<?php

declare(strict_types=1);

namespace Tsduck;

/**
 * MPEG Transport Stream constants.
 *
 * Exposes the same set of transport stream constants as the Python bindings,
 * covering packet sizes, clock frequencies, PCR/PTS/DTS parameters, and
 * related utility values.
 */
final class Constants
{
    /**
     * MPEG TS packet size in bytes.
     */
    public const int PKT_SIZE = 188;

    /**
     * MPEG TS packet size in bits.
     */
    public const int PKT_SIZE_BITS = 8 * self::PKT_SIZE;

    /**
     * Size in bytes of a Reed-Solomon outer FEC.
     */
    public const int RS_SIZE = 16;

    /**
     * Size in bytes of a TS packet with trailing Reed-Solomon outer FEC.
     */
    public const int PKT_RS_SIZE = self::PKT_SIZE + self::RS_SIZE;

    /**
     * Size in bytes of a timestamp preceding a TS packet in M2TS files (Blu-ray disc).
     */
    public const int M2TS_HEADER_SIZE = 4;

    /**
     * Size in bytes of a TS packet in M2TS files (Blu-ray disc).
     */
    public const int PKT_M2TS_SIZE = self::M2TS_HEADER_SIZE + self::PKT_SIZE;

    /**
     * MPEG-2 System Clock frequency in Hz, used by PCR (27 Mb/s).
     */
    public const int SYSTEM_CLOCK_FREQ = 27_000_000;

    /**
     * Subfactor of MPEG-2 System Clock subfrequency, used by PTS and DTS.
     */
    public const int SYSTEM_CLOCK_SUBFACTOR = 300;

    /**
     * MPEG-2 System Clock subfrequency in Hz, used by PTS and DTS (90 Kb/s).
     */
    public const int SYSTEM_CLOCK_SUBFREQ = self::SYSTEM_CLOCK_FREQ / self::SYSTEM_CLOCK_SUBFACTOR;

    /**
     * Size in bits of a PCR (Program Clock Reference).
     *
     * Warning: A PCR value is not a linear value mod 2^42.
     * It is split into PCR_base and PCR_ext (see ISO 13818-1, 2.4.2.2).
     */
    public const int PCR_BIT_SIZE = 42;

    /**
     * Size in bits of a PTS (Presentation Time Stamp) or DTS (Decoding Time Stamp).
     *
     * Unlike PCR, PTS and DTS are regular 33-bit binary values, wrapping up at 2^33.
     */
    public const int PTS_DTS_BIT_SIZE = 33;

    /**
     * Scale factor for PTS and DTS values (wrap up at 2^33).
     */
    public const int PTS_DTS_SCALE = 1 << self::PTS_DTS_BIT_SIZE;

    /**
     * Mask for PTS and DTS values (wrap up at 2^33).
     */
    public const int PTS_DTS_MASK = self::PTS_DTS_SCALE - 1;

    /**
     * The maximum value possible for a PTS/DTS value.
     */
    public const int MAX_PTS_DTS = self::PTS_DTS_SCALE - 1;

    /**
     * Scale factor for PCR values.
     *
     * This is not a power of 2, it does not wrap up at a number of bits.
     * The PCR_base part is equivalent to a PTS/DTS and wraps up at 2^33.
     * The PCR_ext part is a mod 300 value. Note that, since this is not a
     * power of 2, there is no possible PCR_MASK value.
     */
    public const int PCR_SCALE = self::PTS_DTS_SCALE * self::SYSTEM_CLOCK_SUBFACTOR;

    /**
     * The maximum value possible for a PCR (Program Clock Reference) value.
     */
    public const int MAX_PCR = self::PCR_SCALE - 1;

    /**
     * An invalid PCR (Program Clock Reference) value, can be used as a marker.
     */
    public const int INVALID_PCR = -1;

    /**
     * An invalid PTS value, can be used as a marker.
     */
    public const int INVALID_PTS = -1;

    /**
     * An invalid DTS value, can be used as a marker.
     */
    public const int INVALID_DTS = -1;

    /**
     * Private constructor — this class only exposes constants.
     */
    private function __construct()
    {
    }
}
