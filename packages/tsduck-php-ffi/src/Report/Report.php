<?php

declare(strict_types=1);

namespace Tsduck\Report;

use FFI;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Util\InBuffer;
use Tsduck\Util\NativeObject;

/**
 * Base class for TSDuck report objects.
 *
 * Provides severity level constants, a static header() method for log message
 * formatting, and convenience logging methods (error, warning, info, verbose,
 * debug). Subclasses wrap concrete C++ report implementations (NullReport,
 * StdErrReport, AsyncReport).
 *
 * Singleton subclasses (NullReport, StdErrReport) must NOT free their native
 * objects since they are process-global. They override doClose() as a no-op.
 *
 * @see NullReport
 * @see StdErrReport
 * @see AsyncReport
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
abstract class Report extends NativeObject
{
    /**
     * Severity levels, matching the C++ counterparts.
     *
     * Lower values indicate more severe messages. The report filters messages
     * based on its configured maximum severity level.
     */
    public const Fatal   = -5;
    public const Severe  = -4;
    public const Error   = -3;
    public const Warning = -2;
    public const Info    = -1;
    public const Verbose =  0;
    public const Debug   =  1;

    /**
     * @param FFI       $ffi     The FFI instance bound to libtsduck
     * @param FFI\CData $pointer The opaque pointer returned by a tspy* constructor
     */
    public function __construct(FFI $ffi, FFI\CData $pointer)
    {
        parent::__construct($ffi, $pointer);
    }

    /**
     * Returns the formatted line prefix header for a given severity level.
     *
     * Uses tspyReportHeader to generate the standard TSDuck log header
     * format (e.g., "Error: ", "Warning: "). Returns an empty string for
     * Info and Verbose levels.
     *
     * This is a static method that does not require a report instance.
     *
     * @param int $severity The severity level (one of the class constants)
     *
     * @return string The formatted header prefix, or empty string for Info/Verbose
     */
    public static function header(int $severity): string
    {
        $ffi = LibTSDuck::getInstance();
        $buf = new InBuffer($ffi);
        $outSize = $ffi->new('size_t', false, false);
        $outSize->cdata = 64;
        $outBuf = $ffi->new('uint8_t[64]', false, false);

        $ffi->tspyReportHeader($severity, $outBuf, FFI::addr($outSize));

        $actualSize = (int) $outSize->cdata;
        if ($actualSize === 0) {
            return '';
        }

        $bytes = '';
        for ($i = 0; $i < $actualSize; $i++) {
            $bytes .= chr($outBuf[$i]);
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
    }

    /**
     * Sets the maximum severity level for this report.
     *
     * Messages with a severity higher than the configured maximum are suppressed.
     * For example, setting the maximum to Warning will suppress Info, Verbose,
     * and Debug messages but allow Error, Severe, and Fatal messages through.
     *
     * @param int $level The maximum severity level (one of the class constants)
     */
    public function setMaxSeverity(int $level): void
    {
        $this->assertNotClosed();
        $this->ffi->tspySetMaxSeverity($this->getPointer(), $level);
    }

    /**
     * Logs a message at the specified severity level.
     *
     * The message string is converted from PHP UTF-8 to UTF-16 LE before
     * being passed to the C API.
     *
     * @param int    $severity The severity level
     * @param string $message  The message to log
     */
    public function log(int $severity, string $message): void
    {
        $this->assertNotClosed();
        $buf = new InBuffer($this->ffi);
        $buf->append($message);
        $this->ffi->tspyLogReport($this->getPointer(), $severity, $buf->getBuffer(), $buf->getSize());
    }

    /**
     * Logs a message at the Error severity level.
     *
     * @param string $message The message to log
     */
    public function error(string $message): void
    {
        $this->log(self::Error, $message);
    }

    /**
     * Logs a message at the Warning severity level.
     *
     * @param string $message The message to log
     */
    public function warning(string $message): void
    {
        $this->log(self::Warning, $message);
    }

    /**
     * Logs a message at the Info severity level.
     *
     * @param string $message The message to log
     */
    public function info(string $message): void
    {
        $this->log(self::Info, $message);
    }

    /**
     * Logs a message at the Verbose severity level.
     *
     * @param string $message The message to log
     */
    public function verbose(string $message): void
    {
        $this->log(self::Verbose, $message);
    }

    /**
     * Logs a message at the Debug severity level.
     *
     * @param string $message The message to log
     */
    public function debug(string $message): void
    {
        $this->log(self::Debug, $message);
    }
}
