<?php

namespace App\Modules\Transcoder;

use App\Modules\Transcoder\Exception\ConnectionException;
use App\Modules\Transcoder\Exception\ProtocolException;
use App\Modules\Transcoder\Exception\ReadException;
use App\Modules\Transcoder\Exception\WriteException;
use App\Modules\Transcoder\Protocol\{HttpRequest, HttpResponse, MessageFlags, MessageType, ProtocolConstants};
use LogicException;
use ValueError;

/**
 * Unix Socket Client for Transcoder Service
 *
 * Communicates with the Node.js transcoder service over Unix domain sockets
 * using a custom binary protocol.
 */
class SocketClient
{
    private const string DEFAULT_SOCKET_PATH = '/tmp/transcoder.sock';

    /**
     * @var null|resource
     */
    private $socket = null;
    private bool $ownsConnection = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly string $socketPath = self::DEFAULT_SOCKET_PATH,
        private readonly float  $timeout = ProtocolConstants::DEFAULT_TIMEOUT,
        private readonly int    $maxMessageSize = ProtocolConstants::DEFAULT_MAX_MESSAGE_SIZE,
        private readonly int    $readBufferSize = 65536,
        private readonly int    $writeBufferSize = 65536,
        private readonly array  $options = [],
    )
    {
    }

    /**
     * Get socket path
     */
    public function getSocketPath(): string
    {
        return $this->socketPath;
    }

    /**
     * Get timeout in seconds
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * Send HTTP request and get response
     *
     * @throws ConnectionException
     * @throws WriteException
     * @throws ReadException
     * @throws ProtocolException
     */
    public function sendRequest(HttpRequest $request): HttpResponse
    {
        $this->ensureConnected();

        try {
            $this->writeRequest($request);
            return $this->readResponse();
        } catch (WriteException $e) {
            // Connection may be in bad state, close it
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Ensure connected
     *
     * @throws ConnectionException
     */
    private function ensureConnected(): void
    {
        if (!$this->isConnected()) {
            throw ConnectionException::notConnected();
        }
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->socket !== null && is_resource($this->socket);
    }

    /**
     * Write HTTP request to socket
     */
    private function writeRequest(HttpRequest $request): void
    {
        $flags = $request->getFlags();
        $headers = $request->getHeadersWithMetadata();

        $this->writeMessage(
            MessageType::HTTP_REQUEST,
            $flags,
            $headers,
            $request->getBody(),
        );
    }

    /**
     * Write a message to the socket
     *
     * @param array<string, mixed>|null $headers
     * @throws WriteException
     */
    private function writeMessage(
        MessageType  $type,
        MessageFlags $flags,
        ?array       $headers,
        ?string      $body,
    ): void
    {
        // Build message
        $message = $this->encodeMessage($type, $flags, $headers, $body);

        // Write to socket
        $bytesToWrite = strlen($message);
        $bytesWritten = @fwrite($this->socket, $message);

        if ($bytesWritten === false) {
            throw WriteException::failed('fwrite returned false');
        }

        if ($bytesWritten !== $bytesToWrite) {
            throw WriteException::incomplete($bytesToWrite, $bytesWritten);
        }

        // Flush output
        if (fflush($this->socket) === false) {
            throw WriteException::failed('fflush failed');
        }
    }

    /**
     * Encode message to binary format
     *
     * @param array<string, mixed>|null $headers
     * @throws WriteException
     */
    private function encodeMessage(
        MessageType  $type,
        MessageFlags $flags,
        ?array       $headers,
        ?string      $body,
    ): string
    {
        // Prepare headers JSON
        $headersJson = '';
        if ($flags->hasHeaders() && $headers !== null) {
            $encoded = json_encode($headers);
            if ($encoded === false) {
                $encodeError = json_last_error_msg();

                throw WriteException::failed('Failed to encode headers JSON', ['json_encode_error' => $encodeError]);
            }
            $headersJson = $encoded;
        }

        // Prepare body
        $bodyBytes = $body ?? '';

        // Build fixed header (8 bytes)
        // N = unsigned long 32 bit big endian
        // C = unsigned char 1 byte
        $header = pack(
            'NC4',
            ProtocolConstants::PROTOCOL_MAGIC,
            ProtocolConstants::PROTOCOL_VERSION,
            $type->value,
            $flags->toInt(),
            0,  // reserved
        );

        if ($header === false) {
            throw WriteException::failed('Failed to pack header');
        }

        $buffers = [$header];

        // Add headers length and headers if present
        if ($flags->hasHeaders()) {
            $lengthBuf = pack('N', strlen($headersJson));
            if ($lengthBuf === false) {
                throw WriteException::failed('Failed to pack headers length');
            }
            $buffers[] = $lengthBuf;
            $buffers[] = $headersJson;
        }

        // Add body length and body if present
        if ($flags->hasBody()) {
            $lengthBuf = pack('J', strlen($bodyBytes));  // J = unsigned long long 64 bit big endian
            if ($lengthBuf === false) {
                throw WriteException::failed('Failed to pack body length');
            }
            $buffers[] = $lengthBuf;
            $buffers[] = $bodyBytes;
        }

        return implode('', $buffers);
    }

    /**
     * Read response from socket
     *
     * @throws ReadException
     * @throws ProtocolException
     */
    private function readResponse(): HttpResponse
    {
        // Read fixed header
        $header = $this->readExact(ProtocolConstants::HEADER_SIZE);
        $unpacked = @unpack('Nmagic/Cversion/Ctype/Cflags/Creserved', $header);

        if ($unpacked === false) {
            throw ProtocolException::malformedMessage('Failed to unpack header');
        }

        // Validate magic
        if ($unpacked['magic'] !== ProtocolConstants::PROTOCOL_MAGIC) {
            throw ProtocolException::invalidMagic($unpacked['magic']);
        }

        // Validate version
        if ($unpacked['version'] !== ProtocolConstants::PROTOCOL_VERSION) {
            throw ProtocolException::unsupportedVersion($unpacked['version']);
        }

        // Parse type and flags
        try {
            $type = MessageType::from($unpacked['type']);
        } catch (ValueError $e) {
            throw ProtocolException::unknownMessageType($unpacked['type'], $e);
        }

        $flags = MessageFlags::fromInt($unpacked['flags']);

        // Read headers if present
        $headers = [];
        if ($flags->hasHeaders()) {
            $headers = $this->readHeaders();
        }

        // Read body if present
        $body = null;
        if ($flags->hasBody()) {
            $body = $this->readBody();
        }

        return HttpResponse::fromProtocolData($type, $flags, $headers, $body);
    }

    /**
     * Read exact number of bytes from socket
     *
     * @throws ReadException
     */
    private function readExact(int $length): string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @fread($this->socket, $remaining);

            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($this->socket);

                if ($info['timed_out'] ?? false) {
                    throw ReadException::timeout($length, $length - $remaining);
                }

                if ($info['eof'] ?? false) {
                    throw ReadException::connectionClosed();
                }

                throw ReadException::failed('Unknown read error');
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * Read headers from socket
     *
     * @return array<string, mixed>
     * @throws ReadException
     * @throws ProtocolException
     */
    private function readHeaders(): array
    {
        $lengthData = $this->readExact(ProtocolConstants::LENGTH_FIELD_SIZE);
        $length = unpack('Nlength', $lengthData)['length'];

        if ($length > $this->maxMessageSize) {
            throw ProtocolException::messageTooLarge($length, $this->maxMessageSize);
        }

        $json = $this->readExact($length);
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw ProtocolException::invalidHeadersJson($json);
        }

        return $decoded;
    }

    /**
     * Read body from socket
     *
     * @throws ReadException
     * @throws ProtocolException
     */
    private function readBody(): string
    {
        $lengthData = $this->readExact(ProtocolConstants::BODY_LENGTH_FIELD_SIZE);
        $length = unpack('Jlength', $lengthData)['length'];

        if ($length > PHP_INT_MAX) {
            throw ProtocolException::messageTooLarge((int)$length, $this->maxMessageSize);
        }

        $length = (int)$length;

        if ($length > $this->maxMessageSize) {
            throw ProtocolException::messageTooLarge($length, $this->maxMessageSize);
        }

        return $this->readExact($length);
    }

    /**
     * Disconnect from the socket server
     */
    public function disconnect(): void
    {
        if ($this->socket !== null && $this->ownsConnection) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->ownsConnection = false;
    }

    /**
     * Send a ping message
     *
     * @throws ConnectionException
     * @throws WriteException
     * @throws ReadException
     * @throws ProtocolException
     */
    public function ping(): bool
    {
        $this->ensureConnected();

        $this->writeMessage(
            MessageType::PING,
            MessageFlags::none(),
            null,
            null,
        );

        $response = $this->readResponse();

        return $response->getType() === MessageType::PONG;
    }

    /**
     * Execute callback with automatic connection management
     *
     * @param callable(SocketClient): T $callback
     * @return T
     * @template T
     */
    public function withConnection(callable $callback): mixed
    {
        $this->connect();

        try {
            return $callback($this);
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Connect to the socket server
     *
     * @throws ConnectionException
     */
    public function connect(): void
    {
        if ($this->isConnected()) {
            throw ConnectionException::alreadyConnected($this->socketPath);
        }

        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errorMessage,
            $this->timeout,
        );

        if ($socket === false) {
            throw ConnectionException::fromError($this->socketPath, $errno, $errorMessage);
        }

        $this->configureSocket($socket);
        $this->socket = $socket;
        $this->ownsConnection = true;
    }

    /**
     * Configure socket options
     */
    private function configureSocket($socket): void
    {
        // Set timeout
        $seconds = (int)$this->timeout;
        $microseconds = (int)(($this->timeout - $seconds) * 1000000);

        if (!stream_set_timeout($socket, $seconds, $microseconds)) {
            throw ConnectionException::timeout($this->socketPath, $this->timeout);
        }

        // Set buffers
        stream_set_read_buffer($socket, $this->readBufferSize);
        stream_set_write_buffer($socket, $this->writeBufferSize);

        // Set blocking mode
        stream_set_blocking($socket, true);
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Prevent unserialization
     */
    public function __wakeup(): void
    {
        throw new LogicException('Cannot unserialize SocketClient');
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }
}
