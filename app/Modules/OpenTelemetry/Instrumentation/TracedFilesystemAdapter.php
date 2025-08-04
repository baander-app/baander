<?php

namespace App\Modules\OpenTelemetry\Instrumentation;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use App\Modules\OpenTelemetry\SpanBuilder;
use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\StatusCode;

class TracedFilesystemAdapter implements Filesystem
{
    private Filesystem $disk;
    private OpenTelemetryManager $telemetry;
    private string $diskName;

    public function __construct(Filesystem $disk, OpenTelemetryManager $telemetry, string $diskName)
    {
        $this->disk = $disk;
        $this->telemetry = $telemetry;
        $this->diskName = $diskName;
    }

    public function exists($path): bool
    {
        return $this->traceOperation('exists', func_get_args());
    }

    public function get($path): string
    {
        return $this->traceOperation('get', func_get_args());
    }

    public function put($path, $contents, $options = []): bool
    {
        return $this->traceOperation('put', func_get_args());
    }

    public function putFile($path, $file = null, $options = [])
    {
        return $this->traceOperation('putFile', func_get_args());
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->traceOperation('putFileAs', func_get_args());
    }

    public function prepend($path, $data): bool
    {
        return $this->traceOperation('prepend', func_get_args());
    }

    public function append($path, $data): bool
    {
        return $this->traceOperation('append', func_get_args());
    }

    public function delete($paths): bool
    {
        return $this->traceOperation('delete', func_get_args());
    }

    public function copy($from, $to): bool
    {
        return $this->traceOperation('copy', func_get_args());
    }

    public function move($from, $to): bool
    {
        return $this->traceOperation('move', func_get_args());
    }

    public function size($path): int
    {
        return $this->disk->size($path);
    }

    public function lastModified($path): int
    {
        return $this->disk->lastModified($path);
    }

    public function files($directory = null, $recursive = false): array
    {
        return $this->disk->files($directory, $recursive);
    }

    public function allFiles($directory = null): array
    {
        return $this->disk->allFiles($directory);
    }

    public function directories($directory = null, $recursive = false): array
    {
        return $this->disk->directories($directory, $recursive);
    }

    public function allDirectories($directory = null): array
    {
        return $this->disk->allDirectories($directory);
    }

    public function makeDirectory($path): bool
    {
        return $this->traceOperation('makeDirectory', func_get_args());
    }

    public function deleteDirectory($directory): bool
    {
        return $this->traceOperation('deleteDirectory', func_get_args());
    }

    public function setVisibility($path, $visibility): bool
    {
        return $this->traceOperation('setVisibility', func_get_args());
    }

    public function getVisibility($path): string
    {
        return $this->disk->getVisibility($path);
    }

    public function path($path): string
    {
        return $this->disk->path($path);
    }

    public function writeStream($path, $resource, array $options = []): bool
    {
        return $this->traceOperation('writeStream', func_get_args());
    }

    public function readStream($path)
    {
        return $this->traceOperation('readStream', func_get_args());
    }

    public function __call($method, $parameters)
    {
        // Handle additional methods that might exist on the concrete implementation
        $tracedMethods = [
            'download', 'response', 'upload', 'temporaryUrl', 'url', 'missing'
        ];

        if (in_array($method, $tracedMethods)) {
            return $this->traceOperation($method, $parameters);
        }

        return $this->disk->$method(...$parameters);
    }

    private function traceOperation(string $method, array $parameters)
    {
        return SpanBuilder::create("filesystem.{$method}")
            ->asClient()
            ->attributes([
                'filesystem.disk' => $this->diskName,
                'filesystem.operation' => $method,
                'filesystem.path' => $this->extractPath($method, $parameters),
            ])
            ->tags([
                'filesystem.disk' => $this->diskName,
                'filesystem.operation' => $method,
            ])
            ->trace(function ($span) use ($method, $parameters) {
                try {
                    $result = $this->disk->$method(...$parameters);

                    // Add operation-specific attributes
                    if ($method === 'put' && isset($parameters[1])) {
                        $span->setAttribute('filesystem.content_length', strlen($parameters[1]));
                    }

                    if (in_array($method, ['copy', 'move']) && isset($parameters[1])) {
                        $span->setAttribute('filesystem.destination', $parameters[1]);
                    }

                    if (in_array($method, ['putFile', 'putFileAs']) && isset($parameters[1])) {
                        $span->setAttribute('filesystem.file_upload', true);
                    }

                    if ($method === 'setVisibility' && isset($parameters[1])) {
                        $span->setAttribute('filesystem.visibility', $parameters[1]);
                    }

                    if (in_array($method, ['writeStream', 'readStream'])) {
                        $span->setAttribute('filesystem.stream_operation', true);
                    }

                    $span->setAttribute('filesystem.result', 'success');

                    Log::channel('otel_debug')->info('Filesystem operation completed', [
                        'disk' => $this->diskName,
                        'operation' => $method,
                        'path' => $this->extractPath($method, $parameters),
                    ]);

                    return $result;
                } catch (Exception $e) {
                    $span->recordException($e);
                    $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

                    Log::channel('otel_debug')->error('Filesystem operation failed', [
                        'disk' => $this->diskName,
                        'operation' => $method,
                        'path' => $this->extractPath($method, $parameters),
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            });
    }

    private function extractPath(string $method, array $parameters): string
    {
        if (empty($parameters)) {
            return 'unknown';
        }

        // Most methods have path as first parameter
        if (in_array($method, ['get', 'put', 'exists', 'delete', 'size', 'lastModified', 'makeDirectory', 'deleteDirectory', 'setVisibility', 'getVisibility', 'writeStream', 'readStream'])) {
            return $parameters[0] ?? 'unknown';
        }

        // Copy and move have source path as first parameter
        if (in_array($method, ['copy', 'move'])) {
            return $parameters[0] ?? 'unknown';
        }

        // putFile and putFileAs have destination path as first parameter
        return $parameters[0] ?? 'unknown';

    }
}