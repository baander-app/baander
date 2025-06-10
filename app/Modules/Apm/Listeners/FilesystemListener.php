<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\Middleware\TrackDownloadsMiddleware;
use App\Modules\Apm\OctaneApmManager;
use Elastic\Apm\SpanInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;

class FilesystemListener
{
    private array $activeOperations = [];
    private array $metrics = [];
    private bool $enabled = true;
    private OctaneApmManager $apmManager;
    private array $activeSpans = [];

    public function __construct(OctaneApmManager $apmManager)
    {
        $this->apmManager = $apmManager;
        $this->enabled = config('apm.monitoring.filesystem', true);

        if ($this->enabled) {
            $this->registerUploadDownloadTracking();
        }
    }

    /**
     * Track a file operation with APM instrumentation and spans
     */
    public function trackFileOperation(string $operation, string $path, callable $callback, array $context = []): mixed
    {
        if (!$this->enabled) {
            return $callback();
        }

        $operationId = $this->startFileOperation($operation, $path, $context);

        // Create APM span for the file operation
        $span = $this->createFileOperationSpan($operation, $path, $context);

        try {
            $result = $callback();
            $this->completeFileOperation($operationId, ['success' => true], null, $span);
            return $result;
        } catch (\Throwable $e) {
            $this->completeFileOperation($operationId, ['success' => false], $e, $span);
            throw $e;
        }
    }

    /**
     * Create APM span for file operation
     */
    private function createFileOperationSpan(string $operation, string $path, array $context = []): ?SpanInterface
    {
        $spanName = "filesystem.{$operation}";
        $span = $this->apmManager->createSpan($spanName, 'storage', 'filesystem', $operation);

        if ($span) {
            // Add file operation context to span
            $spanContext = [
                'file.path' => $this->sanitizePath($path),
                'file.operation' => $operation,
            ];

            // Add file size if available
            if (isset($context['size'])) {
                $spanContext['file.size'] = $context['size'];
                $spanContext['file.size_mb'] = round($context['size'] / 1024 / 1024, 2);
            }

            // Add file type if available
            if (isset($context['mime_type'])) {
                $spanContext['file.mime_type'] = $context['mime_type'];
            }

            // Add filename if available
            if (isset($context['filename'])) {
                $spanContext['file.name'] = $context['filename'];
            }

            // Add direction (upload/download)
            if (isset($context['direction'])) {
                $spanContext['file.direction'] = $context['direction'];
            }

            // Add response type if available
            if (isset($context['response_type'])) {
                $spanContext['http.response_type'] = $context['response_type'];
            }

            // Add custom context
            foreach ($context as $key => $value) {
                if (!in_array($key, ['size', 'mime_type', 'filename', 'direction', 'response_type']) && !is_array($value)) {
                    $spanContext["file.{$key}"] = $value;
                }
            }

            $this->apmManager->setSpanContext($span, $spanContext);
        }

        return $span;
    }

    /**
     * Track file upload with metrics and spans
     */
    public function trackFileUpload(string $filename, string $path, int $size, callable $callback): mixed
    {
        return $this->trackFileOperation('upload', $path, $callback, [
            'filename' => $filename,
            'size' => $size,
            'type' => 'upload',
            'direction' => 'inbound',
        ]);
    }

    /**
     * Track file download with metrics and spans
     */
    public function trackFileDownload(string $path, callable $callback): mixed
    {
        return $this->trackFileOperation('download', $path, $callback, [
            'size' => file_exists($path) ? filesize($path) : 0,
            'type' => 'download',
            'direction' => 'outbound',
        ]);
    }

    /**
     * Start a file operation and return operation ID
     */
    public function startFileOperation(string $operation, string $path, array $context = []): string
    {
        $operationId = Str::uuid()->toString();
        $startTime = microtime(true);

        $this->activeOperations[$operationId] = [
            'operation' => $operation,
            'path' => $path,
            'start_time' => $startTime,
            'context' => $context,
        ];

        // Log operation start
        $this->logOperation('start', $operation, $path, $context);

        return $operationId;
    }

    /**
     * Complete a file operation with span completion
     */
    public function completeFileOperation(string $operationId, array $context = [], ?\Throwable $error = null, ?SpanInterface $span = null): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            return;
        }

        $operation = $this->activeOperations[$operationId];
        $endTime = microtime(true);
        $duration = ($endTime - $operation['start_time']) * 1000; // Convert to milliseconds

        $finalContext = array_merge($operation['context'], $context, [
            'duration_ms' => $duration,
            'success' => $error === null,
            'completed_at' => now()->toISOString(),
        ]);

        // Complete the APM span
        if ($span) {
            $this->completeFileOperationSpan($span, $finalContext, $error);
        }

        if ($error) {
            $finalContext['error'] = [
                'message' => $error->getMessage(),
                'type' => get_class($error),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ];

            // Record exception in APM
            $this->apmManager->recordException($error, [
                'filesystem_operation' => $operation['operation'],
                'filesystem_path' => $this->sanitizePath($operation['path']),
            ]);
        }

        // Log operation completion
        $this->logOperation('complete', $operation['operation'], $operation['path'], $finalContext);

        // Update metrics
        $this->updateMetrics($operation['operation'], $duration, $finalContext);

        // Clean up
        unset($this->activeOperations[$operationId]);
    }

    /**
     * Complete file operation span with results
     */
    private function completeFileOperationSpan(SpanInterface $span, array $context, ?\Throwable $error = null): void
    {
        try {
            // Add completion context
            $this->apmManager->addSpanTag($span, 'file.duration_ms', (string)($context['duration_ms'] ?? 0));
            $this->apmManager->addSpanTag($span, 'file.success', $context['success'] ? 'true' : 'false');

            if (isset($context['bytes_transferred'])) {
                $this->apmManager->addSpanTag($span, 'file.bytes_transferred', (string)$context['bytes_transferred']);
            }

            if (isset($context['status_code'])) {
                $this->apmManager->addSpanTag($span, 'http.status_code', (string)$context['status_code']);
            }

            if (isset($context['content_length'])) {
                $this->apmManager->addSpanTag($span, 'http.content_length', (string)$context['content_length']);
            }

            if (isset($context['disposition'])) {
                $this->apmManager->addSpanTag($span, 'http.content_disposition', (string)$context['disposition']);
            }

            // Add performance tags
            if (isset($context['duration_ms'])) {
                $duration = $context['duration_ms'];
                if ($duration > 5000) {
                    $this->apmManager->addSpanTag($span, 'performance.slow_operation', 'true');
                }

                // Calculate throughput if we have size
                if (isset($context['size']) && $duration > 0) {
                    $throughput = ($context['size'] / 1024 / 1024) / ($duration / 1000); // MB/s
                    $this->apmManager->addSpanTag($span, 'performance.throughput_mbps', (string)round($throughput, 2));
                }
            }

            // Set span outcome
            if ($error) {
                $span->setOutcome('failure');
                $this->apmManager->addSpanTag($span, 'error.type', get_class($error));
                $this->apmManager->addSpanTag($span, 'error.message', $error->getMessage());
            } else {
                $span->setOutcome('success');
            }

            // End the span
            $span->end();
        } catch (\Throwable $e) {
            $this->logError('Failed to complete file operation span', $e);
        }
    }

    /**
     * Track chunked file upload with span
     */
    public function trackChunkedUpload(string $chunkPath, int $chunkNumber, int $totalChunks, callable $uploadCallback): mixed
    {
        return $this->trackFileOperation('chunk_upload', $chunkPath, $uploadCallback, [
            'chunk_number' => $chunkNumber,
            'total_chunks' => $totalChunks,
            'is_final_chunk' => $chunkNumber === $totalChunks,
            'chunk_progress' => round(($chunkNumber / $totalChunks) * 100, 2),
            'direction' => 'inbound',
        ]);
    }

    /**
     * Track bulk file operations with span
     */
    public function trackBulkOperation(string $operation, array $paths, callable $bulkCallback): mixed
    {
        $pathsPreview = implode(',', array_slice($paths, 0, 3)) . (count($paths) > 3 ? '...' : '');

        return $this->trackFileOperation("bulk_{$operation}", $pathsPreview, $bulkCallback, [
            'files_count' => count($paths),
            'total_size' => $this->calculateTotalSize($paths),
            'is_bulk_operation' => true,
            'operation_type' => $operation,
        ]);
    }

    /**
     * Track file serving for Swoole with span
     */
    public function trackFileServingImmediate(string $filePath, string $responseType = 'download', array $context = []): void
    {
        $span = $this->createFileOperationSpan($responseType, $filePath, $context);

        $operationContext = array_merge([
            'size' => file_exists($filePath) ? filesize($filePath) : 0,
            'mime_type' => file_exists($filePath) ? (mime_content_type($filePath) ?: 'application/octet-stream') : null,
            'response_type' => $responseType,
            'tracked_at' => now()->toISOString(),
            'success' => true,
            'duration_ms' => 0, // Immediate completion
        ], $context);

        // Complete span immediately for Swoole
        if ($span) {
            $this->completeFileOperationSpan($span, $operationContext, null);
        }

        // Log the operation
        $this->logOperation('immediate', $responseType, $filePath, $operationContext);
    }

    /**
     * Create a stored span for long-running file operations
     */
    public function createStoredFileSpan(string $operation, string $path, array $context = []): ?SpanInterface
    {
        $spanName = "filesystem.{$operation}";
        $span = $this->apmManager->beginAndStoreSpan($spanName, 'storage');

        if ($span) {
            $spanContext = [
                'file.path' => $this->sanitizePath($path),
                'file.operation' => $operation,
                'file.stored_span' => true,
            ];

            // Add context from the operation
            foreach ($context as $key => $value) {
                if (!is_array($value)) {
                    $spanContext["file.{$key}"] = $value;
                }
            }

            $this->apmManager->setSpanContext($span, $spanContext);

            // Store reference for later completion
            $this->activeSpans[$spanName] = $span;
        }

        return $span;
    }

    /**
     * End a stored file span
     */
    public function endStoredFileSpan(string $spanName, array $context = [], ?\Throwable $error = null): void
    {
        try {
            // Add final context to the stored span if we have a reference
            if (isset($this->activeSpans[$spanName])) {
                $span = $this->activeSpans[$spanName];

                // Add completion context
                foreach ($context as $key => $value) {
                    if (!is_array($value)) {
                        $this->apmManager->addSpanTag($span, "file.{$key}", (string)$value);
                    }
                }

                if ($error) {
                    $span->setOutcome('failure');
                    $this->apmManager->addSpanTag($span, 'error.type', get_class($error));
                    $this->apmManager->addSpanTag($span, 'error.message', $error->getMessage());
                } else {
                    $span->setOutcome('success');
                }

                unset($this->activeSpans[$spanName]);
            }

            if ($error) {
                $this->apmManager->recordException($error, [
                    'stored_span' => $spanName,
                    'context' => $context,
                ]);
            }

            $this->apmManager->endStoredSpan($spanName);
        } catch (\Throwable $e) {
            $this->logError('Failed to end stored file span', $e, ['span_name' => $spanName]);
        }
    }

    /**
     * Register upload/download tracking (Swoole compatible)
     */
    private function registerUploadDownloadTracking(): void
    {
        if (!config('apm.monitoring.filesystem_uploads', true)) {
            return;
        }

        // Listen for HTTP file upload events
        Event::listen('uploading', function ($file, $path) {
            $this->trackFileUpload(
                $file->getClientOriginalName(),
                $path,
                $file->getSize(),
                function() use ($file, $path) {
                    return $file->store($path);
                }
            );
        });

        // Listen for file download events
        Event::listen('downloading', function ($path) {
            $this->trackFileDownload($path, function() use ($path) {
                return response()->download($path);
            });
        });

        // Track Laravel's file response downloads
        if (class_exists('Symfony\Component\HttpFoundation\BinaryFileResponse')) {
            $this->registerBinaryFileResponseTracking();
        }

        // Track streaming downloads
        $this->registerStreamingDownloadTracking();
    }

    /**
     * Register binary file response tracking (Swoole compatible)
     */
    private function registerBinaryFileResponseTracking(): void
    {
        // Register response macro for download tracking
        Response::macro('downloadWithTracking', function ($file, $name = null, array $headers = [], $disposition = 'attachment') {
            $listener = app(FilesystemListener::class);

            return $listener->trackFileDownload($file, function() use ($file, $name, $headers, $disposition) {
                return response()->download($file, $name, $headers, $disposition);
            });
        });

        // Register response macro for file tracking
        Response::macro('fileWithTracking', function ($file, array $headers = []) {
            $listener = app(FilesystemListener::class);

            return $listener->trackFileDownload($file, function() use ($file, $headers) {
                return response()->file($file, $headers);
            });
        });

        // Register response macro for stream download tracking
        Response::macro('streamDownloadWithTracking', function ($callback, $name = null, array $headers = []) {
            $listener = app(FilesystemListener::class);

            return $listener->trackFileOperation('stream_download', $name ?: 'stream', function() use ($callback, $name, $headers) {
                return response()->streamDownload($callback, $name, $headers);
            }, [
                'is_stream' => true,
                'filename' => $name,
                'direction' => 'outbound',
            ]);
        });

        // Hook into Symfony's BinaryFileResponse if available
        if (class_exists('Symfony\Component\HttpFoundation\BinaryFileResponse')) {
            $this->hookBinaryFileResponse();
        }

        // Register middleware for automatic tracking
        $this->registerDownloadMiddleware();
    }

    /**
     * Hook into Symfony's BinaryFileResponse (Swoole compatible)
     */
    private function hookBinaryFileResponse(): void
    {
        // Register a service provider hook that tracks immediately
        app()->resolving(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, function ($response) {
            $file = $response->getFile();
            if ($file && $file->isFile()) {
                // Track immediately since we have file info
                $this->trackFileServingImmediate($file->getPathname(), 'binary_response', [
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'disposition' => $response->headers->get('Content-Disposition', 'inline'),
                    'resolved_at' => now()->toISOString(),
                    'direction' => 'outbound',
                ]);
            }
        });
    }

    /**
     * Register streaming download tracking (Swoole compatible)
     */
    private function registerStreamingDownloadTracking(): void
    {
        // Track streaming responses that serve files
        Event::listen('response.streaming', function ($response, $path = null) {
            if ($path && file_exists($path)) {
                $this->trackFileServingImmediate($path, 'stream_download', [
                    'size' => filesize($path),
                    'mime_type' => mime_content_type($path) ?: 'application/octet-stream',
                    'is_stream' => true,
                    'direction' => 'outbound',
                ]);
            }
        });
    }

    /**
     * Register download middleware for automatic tracking
     */
    private function registerDownloadMiddleware(): void
    {
        // Register the middleware class
        app('router')->aliasMiddleware('track-downloads', TrackDownloadsMiddleware::class);
    }

    /**
     * Calculate total size for multiple files
     */
    private function calculateTotalSize(array $paths): int
    {
        $totalSize = 0;
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $totalSize += filesize($path);
            }
        }
        return $totalSize;
    }

    /**
     * Log file operation
     */
    private function logOperation(string $phase, string $operation, string $path, array $context): void
    {
        $logData = [
            'phase' => $phase,
            'operation' => $operation,
            'path' => $this->sanitizePath($path),
            'context' => $context,
            'timestamp' => now()->toISOString(),
        ];

        // Log to configured channels
        if (config('apm.logging.filesystem', true)) {
            logger()->info("Filesystem operation {$phase}: {$operation}", $logData);
        }
    }

    /**
     * Log errors
     */
    private function logError(string $message, \Throwable $e, array $context = []): void
    {
        logger()->error($message, [
            'exception' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context' => $context,
        ]);
    }

    /**
     * Update metrics for file operations
     */
    private function updateMetrics(string $operation, float $duration, array $context): void
    {
        $key = "filesystem.{$operation}";

        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = [
                'count' => 0,
                'total_duration' => 0,
                'avg_duration' => 0,
                'errors' => 0,
                'total_size' => 0,
                'avg_size' => 0,
                'slow_operations' => 0,
            ];
        }

        $this->metrics[$key]['count']++;
        $this->metrics[$key]['total_duration'] += $duration;
        $this->metrics[$key]['avg_duration'] = $this->metrics[$key]['total_duration'] / $this->metrics[$key]['count'];

        if (!($context['success'] ?? true)) {
            $this->metrics[$key]['errors']++;
        }

        if (isset($context['size'])) {
            $this->metrics[$key]['total_size'] += $context['size'];
            $this->metrics[$key]['avg_size'] = $this->metrics[$key]['total_size'] / $this->metrics[$key]['count'];
        }

        // Track slow operations
        $slowThreshold = config('apm.thresholds.filesystem_slow_operation', 1000);
        if ($duration > $slowThreshold) {
            $this->metrics[$key]['slow_operations']++;
        }

        // Emit metrics periodically
        if ($this->metrics[$key]['count'] % 10 === 0) {
            $this->emitMetrics($key);
        }
    }

    /**
     * Emit metrics to configured endpoints
     */
    private function emitMetrics(string $key): void
    {
        $metrics = $this->metrics[$key] ?? [];

        if (empty($metrics)) {
            return;
        }

        $metricData = [
            'metric' => $key,
            'timestamp' => now()->toISOString(),
            'data' => $metrics,
        ];

        // Log metrics
        if (config('apm.logging.metrics', true)) {
            logger()->info("Filesystem metrics", $metricData);
        }

        // Add as custom tag to current transaction
        if ($this->apmManager->getTransaction()) {
            $this->apmManager->addCustomTag("metrics.{$key}.count", $metrics['count']);
            $this->apmManager->addCustomTag("metrics.{$key}.avg_duration", round($metrics['avg_duration'], 2));
            $this->apmManager->addCustomTag("metrics.{$key}.errors", $metrics['errors']);

            if (isset($metrics['avg_size'])) {
                $this->apmManager->addCustomTag("metrics.{$key}.avg_size_mb", round($metrics['avg_size'] / 1024 / 1024, 2));
            }
        }
    }

    /**
     * Sanitize file path for logging
     */
    private function sanitizePath(string $path): string
    {
        // Remove sensitive information from paths
        $basePath = base_path();
        $storagePath = storage_path();

        $path = str_replace($basePath, '[BASE]', $path);
        $path = str_replace($storagePath, '[STORAGE]', $path);

        return $path;
    }

    /**
     * Get current metrics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Reset metrics
     */
    public function resetMetrics(): void
    {
        $this->metrics = [];
    }

    /**
     * Get active operations count
     */
    public function getActiveOperationsCount(): int
    {
        return count($this->activeOperations);
    }

    /**
     * Get active spans count
     */
    public function getActiveSpansCount(): int
    {
        return count($this->activeSpans);
    }

    /**
     * Enable/disable tracking
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Get filesystem health status
     */
    public function getHealthStatus(): array
    {
        return [
            'enabled' => $this->enabled,
            'active_operations' => $this->getActiveOperationsCount(),
            'active_spans' => $this->getActiveSpansCount(),
            'metrics_count' => count($this->metrics),
            'apm_enabled' => $this->apmManager->isEnabled(),
        ];
    }
}