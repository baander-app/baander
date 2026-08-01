<?php

declare(strict_types=1);

namespace Tsduck;

use FFI;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Report\NullReport;
use Tsduck\Report\Report;
use Tsduck\Util\InBuffer;
use Tsduck\Util\NativeObject;

/**
 * A TSDuck execution context for MPEG-TS processing operations.
 *
 * DuckContext holds configuration and state used throughout the processing
 * of a transport stream, including default character set, CAS id, private
 * data specifier, signalization standards, and time reference settings.
 *
 * Usage:
 *   $duck = new DuckContext();
 *   $duck->addStandards(DuckContext::DVB);
 *   $duck->setDefaultCharset('ISO-8859-15');
 *   // ... use $duck with TSProcessor, SectionFile, etc.
 *   $duck->close();
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class DuckContext extends NativeObject
{
    // =========================================================================
    // Standard bit masks (matching C++ counterparts)
    // =========================================================================

    /** No known standard. */
    public const NONE  = 0x00;

    /** Defined by MPEG, common to all standards. */
    public const MPEG  = 0x01;

    /** Defined by ETSI/DVB. */
    public const DVB   = 0x02;

    /** Defined by ANSI/SCTE. */
    public const SCTE  = 0x04;

    /** Defined by ATSC. */
    public const ATSC  = 0x08;

    /** Defined by ISDB. */
    public const ISDB  = 0x10;

    /** Defined in Japan only (typically in addition to ISDB). */
    public const JAPAN = 0x20;

    /** Defined by ABNT (Brazil, typically in addition to ISDB). */
    public const ABNT  = 0x40;

    /**
     * The report used by this context for logging.
     */
    private readonly object $report;

    /**
     * Creates a new DuckContext.
     *
     * @param Report|null $report The report object to use for logging.
     *                                      Defaults to NullReport::getInstance()
     *                                      which silently discards all messages.
     */
    public function __construct(object $report = null)
    {
        $ffi = LibTSDuck::getInstance();

        if ($report === null) {
            $report = NullReport::getInstance();
        }

        $this->report = $report;

        // Get the opaque pointer from the report.
        $reportPointer = match (true) {
            $report instanceof Report => $report->nativePointer(),
            default => throw new TsduckException(sprintf(
                'Report must be an instance of Report, %s given.',
                get_debug_type($report),
            )),
        };

        // Ensure the pointer is accessible (assertNotClosed pattern).
        // void* tspyNewDuckContext(void* report)
        $pointer = $ffi->tspyNewDuckContext($reportPointer);

        parent::__construct($ffi, $pointer);
    }

    /**
     * Frees the underlying C++ DuckContext object.
     */
    protected function doClose(): void
    {
        $pointer = $this->getPointer();
        if ($pointer !== null) {
            $this->ffi->tspyDeleteDuckContext($pointer);
        }
    }

    /**
     * Sets the default character set for strings.
     *
     * The default should be the DVB superset of ISO/IEC 6937 as defined in
     * ETSI EN 300 468. Use another default in the context of an operator using
     * an incorrect signalization, assuming another default character set (usually
     * from its own country) or in the context of mixed standards (ISDB/DVB).
     *
     * Pass an empty string to revert to the default character set.
     *
     * @param string $charset The character set name, or empty string for default
     *
     * @throws TsduckException If the character set name is invalid
     */
    public function setDefaultCharset(string $charset): void
    {
        $this->assertNotClosed();
        $buf = new InBuffer($this->ffi);
        $buf->append($charset);

        $result = $this->ffi->tspyDuckContextSetDefaultCharset(
            $this->getPointer(),
            $buf->getBuffer(),
            $buf->getSize(),
        );

        if ($result === 0) {
            throw new TsduckException(sprintf(
                'Failed to set default charset "%s": invalid character set name.',
                $charset,
            ));
        }
    }

    /**
     * Sets the default CAS id to use.
     *
     * @param int $casId Default CAS id to be used when the CAS is unknown
     */
    public function setDefaultCASId(int $casId): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyDuckContextSetDefaultCASId($this->getPointer(), $casId);
    }

    /**
     * Sets the default private data specifier.
     *
     * @param int $pds Default PDS. Use zero to revert to no default.
     */
    public function setDefaultPDS(int $pds): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyDuckContextSetDefaultPDS($this->getPointer(), $pds);
    }

    /**
     * Adds standards which are present in the transport stream or context.
     *
     * Accepts a variadic list of standard constants (DuckContext::DVB, etc.).
     * The standards are OR'ed together into a bitmask and passed to the C API.
     *
     * @param int ...$standards One or more standard constants to add
     */
    public function addStandards(int ...$standards): void
    {
        $this->assertNotClosed();

        $mask = 0;
        foreach ($standards as $standard) {
            $mask |= $standard;
        }

        $this->ffi->tspyDuckContextAddStandards($this->getPointer(), $mask);
    }

    /**
     * Resets the list of standards to the specified bitmask.
     *
     * @param int $mask A bitmask of standards (default: DuckContext::NONE)
     */
    public function resetStandards(int $mask = self::NONE): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyDuckContextResetStandards($this->getPointer(), $mask);
    }

    /**
     * Returns the list of standards present in the transport stream or context.
     *
     * @return int A bitmask of standards (OR of DuckContext::* constants)
     */
    public function standards(): int
    {
        $this->assertNotClosed();

        return (int) $this->ffi->tspyDuckContextStandards($this->getPointer());
    }

    /**
     * Sets a non-standard time reference offset.
     *
     * In DVB SI, reference times are UTC. These SI can be reused in
     * non-standard ways where the stored times use another reference.
     * This is the case with ARIB and ABNT variants of ISDB which reuse
     * TOT, TDT and EIT but with another local time reference.
     *
     * @param int $millis Offset from UTC in milliseconds (positive or negative).
     *                    The default offset is zero, meaning plain UTC time.
     */
    public function setTimeReferenceOffset(int $millis): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyDuckContextSetTimeReferenceOffset($this->getPointer(), $millis);
    }

    /**
     * Sets a non-standard time reference offset using a name.
     *
     * The name can be "JST" or "UTC[[+|-]hh[:mm]]".
     *
     * @param string $name Time reference name
     *
     * @throws TsduckException If the time reference name is invalid
     */
    public function setTimeReference(string $name): void
    {
        $this->assertNotClosed();
        $buf = new InBuffer($this->ffi);
        $buf->append($name);

        $result = $this->ffi->tspyDuckContextSetTimeReference(
            $this->getPointer(),
            $buf->getBuffer(),
            $buf->getSize(),
        );

        if ($result === 0) {
            throw new TsduckException(sprintf(
                'Failed to set time reference "%s": invalid name.',
                $name,
            ));
        }
    }
}
