<?php

namespace App\Modules\Transcoder\Protocol;

use InvalidArgumentException;

readonly class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        private HttpMethod $method,
        private string     $path,
        private array      $headers,
        private ?string    $body = null,
        private ?string    $query = null,
    )
    {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('Path must start with "/"');
        }
    }

    /**
     * Create a GET request
     *
     * @param array<string, string> $headers
     */
    public static function get(
        string  $path,
        array   $headers = [],
        ?string $query = null,
    ): self
    {
        return new self(HttpMethod::GET, $path, $headers, null, $query);
    }

    /**
     * Create a POST request
     *
     * @param array<string, string> $headers
     */
    public static function post(
        string $path,
        string $body,
        array  $headers = [],
    ): self
    {
        return new self(HttpMethod::POST, $path, $headers, $body);
    }

    /**
     * Create a PUT request
     *
     * @param array<string, string> $headers
     */
    public static function put(
        string $path,
        string $body,
        array  $headers = [],
    ): self
    {
        return new self(HttpMethod::PUT, $path, $headers, $body);
    }

    /**
     * Create a DELETE request
     *
     * @param array<string, string> $headers
     */
    public static function delete(
        string $path,
        array  $headers = [],
    ): self
    {
        return new self(HttpMethod::DELETE, $path, $headers);
    }

    /**
     * Create a HEAD request
     *
     * @param array<string, string> $headers
     */
    public static function head(
        string $path,
        array  $headers = [],
    ): self
    {
        return new self(HttpMethod::HEAD, $path, $headers);
    }

    /**
     * Create a request with any method
     *
     * @param array<string, string> $headers
     */
    public static function create(
        HttpMethod|string $method,
        string            $path,
        array             $headers = [],
        ?string           $body = null,
        ?string           $query = null,
    ): self
    {
        if (is_string($method)) {
            $method = HttpMethod::from($method);
        }
        return new self($method, $path, $headers, $body, $query);
    }

    public function getMethod(): HttpMethod
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    /**
     * Get full URI with query string
     */
    public function getUri(): string
    {
        $uri = $this->path;
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        return $uri;
    }

    /**
     * Get headers with request metadata for protocol encoding
     *
     * @return array<string, mixed>
     */
    public function getHeadersWithMetadata(): array
    {
        $metadata = array_merge($this->headers, [
            '_method' => $this->method->value,
            '_path'   => $this->path,
        ]);

        if ($this->query !== null && $this->query !== '') {
            $metadata['_query'] = $this->query;
        }

        // Add request ID for tracing if not already set
        if (!isset($metadata['x-request-id'])) {
            $metadata['x-request-id'] = $this->generateRequestId();
        }

        return $metadata;
    }

    /**
     * Generate a unique request ID
     */
    private function generateRequestId(): string
    {
        return sprintf('php-%x-%x', hrtime(true), random_int(1, 0xffffff));
    }

    /**
     * Calculate message flags
     */
    public function getFlags(): MessageFlags
    {
        $flags = MessageFlags::withHeaders();
        if ($this->hasBody()) {
            $flags = $flags->or(MessageFlags::withBody(binary: true));
        }
        return $flags;
    }

    public function hasBody(): bool
    {
        return $this->body !== null;
    }
}
