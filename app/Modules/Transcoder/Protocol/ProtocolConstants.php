<?php

namespace App\Modules\Transcoder\Protocol;
class ProtocolConstants
{
    public const int PROTOCOL_MAGIC = 0x54434E54;  // "TCNT"
    public const int PROTOCOL_VERSION = 0x01;

    public const int HEADER_SIZE = 8;               // Fixed header size in bytes
    public const int LENGTH_FIELD_SIZE = 4;         // Headers length field size
    public const int BODY_LENGTH_FIELD_SIZE = 8;    // Body length field size

    public const float DEFAULT_TIMEOUT = 30.0;        // Default timeout in seconds
    public const int DEFAULT_MAX_MESSAGE_SIZE = 104857600;  // 100MB

    private function __construct()
    {
    }
}
