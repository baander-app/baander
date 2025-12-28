<?php

namespace App\Modules\Transcoder\Protocol;

use App\Extensions\EnumExt;

enum MessageType: int
{
    use EnumExt;

    /** Client -> Server: HTTP request */
    case HTTP_REQUEST = 0x01;

    /** Client -> Server: Ping message */
    case PING = 0x02;

    /** Server -> Client: HTTP response */
    case HTTP_RESPONSE = 0x80;

    /** Server -> Client: Pong message */
    case PONG = 0x81;

    /** Server -> Client: Error response */
    case ERROR = 0x82;

    /**
     * Check if this is a request type (client -> server)
     */
    public function isRequest(): bool
    {
        return $this->value < 0x80;
    }

    /**
     * Check if this is a response type (server -> client)
     */
    public function isResponse(): bool
    {
        return $this->value >= 0x80;
    }

    /**
     * Get the name for debugging
     */
    public function getName(): string
    {
        return $this->name;
    }
}
