<?php

namespace App\Modules\Transcoder\Exception;

/**
 * Protocol-related exceptions
 */
class ProtocolException extends SocketException
{
    /**
     * Invalid magic number
     */
    public static function invalidMagic(int $actualMagic): self
    {
        return new self(
            "Invalid magic number: 0x" . sprintf('%08X', $actualMagic),
            0,
            null,
            ['actual_magic' => $actualMagic],
        );
    }

    /**
     * Unsupported protocol version
     */
    public static function unsupportedVersion(int $version): self
    {
        return new self(
            "Unsupported protocol version: {$version}",
            0,
            null,
            ['version' => $version],
        );
    }

    /**
     * Unknown message type
     */
    public static function unknownMessageType(int $type): self
    {
        return new self(
            "Unknown message type: 0x" . sprintf('%02X', $type),
            0,
            null,
            ['type' => $type],
        );
    }

    /**
     * Invalid headers JSON
     */
    public static function invalidHeadersJson(string $json): self
    {
        $error = json_last_error_msg();

        return new self(
            "Invalid headers JSON: {$error}",
            0,
            null,
            ['json' => $json, 'json_error' => $error],
        );
    }

    /**
     * Message too large
     */
    public static function messageTooLarge(int $size, int $maxSize): self
    {
        return new self(
            "Message body too large: {$size} bytes (max: {$maxSize})",
            0,
            null,
            ['size' => $size, 'max_size' => $maxSize],
        );
    }

    /**
     * Malformed message
     */
    public static function malformedMessage(string $reason): self
    {
        return new self(
            "Malformed message: {$reason}",
            0,
            null,
            ['reason' => $reason],
        );
    }

    /**
     * Unexpected flag combination
     */
    public static function unexpectedFlags(int $flags, string $context): self
    {
        return new self(
            "Unexpected flags in {$context}: 0x" . sprintf('%02X', $flags),
            0,
            null,
            ['flags' => $flags, 'context' => $context],
        );
    }
}
