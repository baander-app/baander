<?php

namespace App\Modules\Transcoder\Protocol;

use App\Modules\Transcoder\Exception\ResponseException;
use JsonException;

readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private MessageType  $type,
        private MessageFlags $flags,
        private array        $headers,
        private ?string      $body,
        private int          $statusCode = 200,
        private string       $statusMessage = 'OK',
    )
    {
    }

    /**
     * Create from protocol data
     *
     * @param array<string, mixed> $headersData
     */
    public static function fromProtocolData(
        MessageType  $type,
        MessageFlags $flags,
        array        $headersData,
        ?string      $body,
    ): self
    {
        if ($type === MessageType::HTTP_RESPONSE) {
            $statusCode = (int)($headersData['_statusCode'] ?? 200);
            $statusMessage = (string)($headersData['_statusMessage'] ?? 'OK');
            unset($headersData['_statusCode']);
            unset($headersData['_statusMessage']);

            // Normalize headers to strings
            $headers = array_map(function ($value) {
                return is_array($value) ? $value[0] ?? '' : (string)$value;
            }, $headersData);

            return new self($type, $flags, $headers, $body, $statusCode, $statusMessage);
        }

        if ($type === MessageType::ERROR) {
            $errorCode = (int)($headersData['_code'] ?? 0);
            $errorMessage = (string)($headersData['_message'] ?? 'Unknown error');

            throw ResponseException::serverError(
                $errorCode,
                $errorMessage,
                $headersData['_details'] ?? null,
            );
        }

        // For PONG or other messages
        return new self($type, $flags, [], $body);
    }

    public function getType(): MessageType
    {
        return $this->type;
    }

    public function getFlags(): MessageFlags
    {
        return $this->flags;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Check if header exists
     */
    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== null;
    }

    /**
     * Get header by name (case-insensitive)
     */
    public function getHeader(string $name): ?string
    {
        $lowerName = strtolower($name);
        return array_find($this->headers, fn($value, $key) => strtolower($key) === $lowerName);
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getStatusMessage(): string
    {
        return $this->statusMessage;
    }

    /**
     * Check if response is successful (2xx status code)
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Check if response is a redirect (3xx status code)
     */
    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /**
     * Check if response is a client error (4xx status code)
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Check if response is a server error (5xx status code)
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    /**
     * Check if response is an error
     */
    public function isError(): bool
    {
        return $this->statusCode >= 400;
    }

    /**
     * Get body as string
     */
    public function getBodyString(): string
    {
        return $this->body ?? '';
    }

    /**
     * Get body decoded as associative array (typed alias)
     *
     * @return array<string, mixed>|null
     * @throws ResponseException if JSON is invalid
     */
    public function getBodyJsonArray(): ?array
    {
        try {
            $json = $this->getBodyJson();
        } catch (\JsonException $exception) {
            throw new ResponseException('Invalid JSON in response body', 500, $exception);
        }

        return is_array($json) ? $json : null;
    }

    /**
     * Get body decoded as JSON
     *
     * @return mixed
     * @throws ResponseException if JSON is invalid
     * @throws JsonException
     */
    public function getBodyJson(): mixed
    {
        if ($this->body === null) {
            return null;
        }

        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Get content length from headers or body
     */
    public function getContentLength(): ?int
    {
        $header = $this->getHeader('content-length');
        if ($header !== null) {
            return (int)$header;
        }

        if ($this->body !== null) {
            return strlen($this->body);
        }

        return null;
    }

    /**
     * Get content type from headers
     */
    public function getContentType(): ?string
    {
        return $this->getHeader('content-type');
    }

    /**
     * Convert to array for debugging
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'          => $this->type->name,
            'flags'         => $this->flags->toString(),
            'statusCode'    => $this->statusCode,
            'statusMessage' => $this->statusMessage,
            'headers'       => $this->headers,
            'bodyLength'    => $this->body !== null ? strlen($this->body) : 0,
        ];
    }
}
