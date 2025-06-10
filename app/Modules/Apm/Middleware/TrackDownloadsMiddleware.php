<?php

namespace App\Modules\Apm\Middleware;

use App\Modules\Apm\Listeners\FilesystemListener;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Middleware to automatically track file downloads (Swoole compatible with APM spans)
 */
class TrackDownloadsMiddleware
{
    public function __construct(
        private FilesystemListener $filesystemListener
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Track binary file responses (downloads)
        if ($response instanceof BinaryFileResponse) {
            $this->trackBinaryFileResponse($response, $request);
        }

        // Track streamed responses that might be file downloads
        elseif ($response instanceof StreamedResponse) {
            $this->trackStreamedResponse($response, $request);
        }

        // Track regular responses that serve files
        elseif ($this->isFileResponse($response)) {
            $this->trackFileResponse($response, $request);
        }

        return $response;
    }

    /**
     * Track binary file response (Swoole compatible with spans)
     */
    private function trackBinaryFileResponse(BinaryFileResponse $response, Request $request): void
    {
        $file = $response->getFile();
        if ($file->isFile()) {
            // Complete tracking immediately since we have all the info
            $this->filesystemListener->trackFileServingImmediate($file->getPathname(), 'binary_download', [
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'disposition' => $response->headers->get('Content-Disposition', 'inline'),
                'filename' => basename($file->getPathname()),
                'status_code' => $response->getStatusCode(),
                'content_length' => $response->headers->get('Content-Length'),
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'referer' => $request->header('Referer'),
                'request_method' => $request->method(),
                'request_uri' => $request->getRequestUri(),
            ]);
        }
    }

    /**
     * Track streamed response (Swoole compatible with spans)
     */
    private function trackStreamedResponse(StreamedResponse $response, Request $request): void
    {
        $filename = $this->extractFilenameFromResponse($response) ?:
            $this->extractFilenameFromRequest($request) ?:
                'streamed_file';

        // For streams, track the initiation immediately
        $this->filesystemListener->trackFileServingImmediate($filename, 'stream_download', [
            'is_stream' => true,
            'content_type' => $response->headers->get('Content-Type'),
            'disposition' => $response->headers->get('Content-Disposition'),
            'status_code' => $response->getStatusCode(),
            'initiated_at' => now()->toISOString(),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'referer' => $request->header('Referer'),
            'request_method' => $request->method(),
            'request_uri' => $request->getRequestUri(),
        ]);
    }

    /**
     * Track file response (Swoole compatible with spans)
     */
    private function trackFileResponse($response, Request $request): void
    {
        $filename = $this->extractFilenameFromResponse($response) ?:
            $this->extractFilenameFromRequest($request) ?:
                'file_response';

        $this->filesystemListener->trackFileServingImmediate($filename, 'file_response', [
            'content_type' => $response->headers->get('Content-Type'),
            'content_length' => $response->headers->get('Content-Length'),
            'status_code' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'referer' => $request->header('Referer'),
            'request_method' => $request->method(),
            'request_uri' => $request->getRequestUri(),
        ]);
    }

    /**
     * Check if response is serving a file
     */
    private function isFileResponse($response): bool
    {
        if (!method_exists($response, 'headers')) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');
        $disposition = $response->headers->get('Content-Disposition', '');

        // Check for file-like content types or download disposition
        return str_contains($disposition, 'attachment') ||
            str_contains($disposition, 'filename') ||
            $this->isFileContentType($contentType);
    }

    /**
     * Check if content type indicates a file
     */
    private function isFileContentType(string $contentType): bool
    {
        $fileContentTypes = [
            'application/pdf',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
            'image/',
            'video/',
            'audio/',
            'application/vnd.',
            'application/msword',
            'application/excel',
            'application/vnd.openxmlformats-officedocument',
            'text/csv',
            'application/json', // If serving JSON files as downloads
            'text/plain', // If serving text files as downloads
            'application/xml',
            'application/x-tar',
            'application/gzip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
        ];

        foreach ($fileContentTypes as $type) {
            if (str_starts_with($contentType, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract filename from response headers
     */
    private function extractFilenameFromResponse($response): ?string
    {
        if (!method_exists($response, 'headers')) {
            return null;
        }

        $disposition = $response->headers->get('Content-Disposition', '');

        // Try different patterns for filename extraction
        $patterns = [
            '/filename\*=UTF-8\'\'([^;]+)/',  // RFC 5987 format
            '/filename[^;=\n]*=(([\'"]).*?\2|[^;\n]*)/',  // Standard format
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $disposition, $matches)) {
                $filename = isset($matches[2]) ? trim($matches[1], '"\'') : trim($matches[1], '"\'');
                return urldecode($filename);
            }
        }

        return null;
    }

    /**
     * Extract filename from request
     */
    private function extractFilenameFromRequest(Request $request): ?string
    {
        $path = $request->path();

        // Extract filename from URL path
        if (preg_match('/([^\/]+\.[a-zA-Z0-9]+)$/', $path, $matches)) {
            return $matches[1];
        }

        // Try to get from query parameters
        if ($request->has('filename')) {
            return $request->get('filename');
        }

        if ($request->has('file')) {
            $file = $request->get('file');
            if (is_string($file) && str_contains($file, '.')) {
                return basename($file);
            }
        }

        return null;
    }
}