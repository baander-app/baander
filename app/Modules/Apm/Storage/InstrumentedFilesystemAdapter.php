<?php

namespace App\Modules\Apm\Storage;

use App\Modules\Apm\Listeners\FilesystemListener;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Filesystem adapter with APM instrumentation
 */
class InstrumentedFilesystemAdapter implements Filesystem
{
    public function __construct(
        private Filesystem $disk,
        private FilesystemListener $listener,
        private ?string $diskName = null
    ) {
    }

    /**
     * Determine if a file exists.
     */
    public function exists($path): bool
    {
        return $this->listener->trackFileOperation('exists', $path, function() use ($path) {
            return $this->disk->exists($path);
        }, ['disk' => $this->diskName]);
    }

    /**
     * Determine if a file or directory is missing.
     */
    public function missing($path): bool
    {
        return $this->listener->trackFileOperation('missing', $path, function() use ($path) {
            return $this->disk->missing($path);
        }, ['disk' => $this->diskName]);
    }

    /**
     * Get the contents of a file.
     */
    public function get($path): string
    {
        return $this->listener->trackFileOperation('read', $path, function() use ($path) {
            return $this->disk->get($path);
        }, [
            'disk' => $this->diskName,
            'size' => $this->disk->exists($path) ? $this->disk->size($path) : 0,
        ]);
    }

    /**
     * Get a resource to read the file.
     */
    public function readStream($path)
    {
        return $this->listener->trackFileOperation('readStream', $path, function() use ($path) {
            return $this->disk->readStream($path);
        }, [
            'disk' => $this->diskName,
            'size' => $this->disk->exists($path) ? $this->disk->size($path) : 0,
        ]);
    }

    /**
     * Write the contents of a file.
     */
    public function put($path, $contents, $options = []): string|bool
    {
        return $this->listener->trackFileOperation('write', $path, function() use ($path, $contents, $options) {
            return $this->disk->put($path, $contents, $options);
        }, [
            'disk' => $this->diskName,
            'size' => is_string($contents) ? strlen($contents) : 0,
        ]);
    }

    /**
     * Write a new file using a stream.
     */
    public function writeStream($path, $resource, array $options = []): bool
    {
        return $this->listener->trackFileOperation('writeStream', $path, function() use ($path, $resource, $options) {
            return $this->disk->writeStream($path, $resource, $options);
        }, [
            'disk' => $this->diskName,
            'is_resource' => is_resource($resource),
        ]);
    }

    /**
     * Get the visibility for the given path.
     */
    public function getVisibility($path): string
    {
        return $this->listener->trackFileOperation('getVisibility', $path, function() use ($path) {
            return $this->disk->getVisibility($path);
        }, ['disk' => $this->diskName]);
    }

    /**
     * Set the visibility for the given path.
     */
    public function setVisibility($path, $visibility): bool
    {
        return $this->listener->trackFileOperation('setVisibility', $path, function() use ($path, $visibility) {
            return $this->disk->setVisibility($path, $visibility);
        }, [
            'disk' => $this->diskName,
            'visibility' => $visibility,
        ]);
    }

    /**
     * Prepend to a file.
     */
    public function prepend($path, $data): bool
    {
        return $this->listener->trackFileOperation('prepend', $path, function() use ($path, $data) {
            return $this->disk->prepend($path, $data);
        }, [
            'disk' => $this->diskName,
            'data_size' => is_string($data) ? strlen($data) : 0,
        ]);
    }

    /**
     * Append to a file.
     */
    public function append($path, $data): bool
    {
        return $this->listener->trackFileOperation('append', $path, function() use ($path, $data) {
            return $this->disk->append($path, $data);
        }, [
            'disk' => $this->diskName,
            'data_size' => is_string($data) ? strlen($data) : 0,
        ]);
    }

    /**
     * Delete the file at a given path.
     */
    public function delete($paths): bool
    {
        $paths = is_array($paths) ? $paths : func_get_args();

        return $this->listener->trackFileOperation('delete', implode(',', $paths), function() use ($paths) {
            return $this->disk->delete($paths);
        }, [
            'disk' => $this->diskName,
            'files_count' => count($paths),
        ]);
    }

    /**
     * Copy a file to a new location.
     */
    public function copy($from, $to): bool
    {
        return $this->listener->trackFileOperation('copy', "$from -> $to", function() use ($from, $to) {
            return $this->disk->copy($from, $to);
        }, [
            'disk' => $this->diskName,
            'source' => $from,
            'destination' => $to,
            'size' => $this->disk->exists($from) ? $this->disk->size($from) : 0,
        ]);
    }

    /**
     * Move a file to a new location.
     */
    public function move($from, $to): bool
    {
        return $this->listener->trackFileOperation('move', "$from -> $to", function() use ($from, $to) {
            return $this->disk->move($from, $to);
        }, [
            'disk' => $this->diskName,
            'source' => $from,
            'destination' => $to,
            'size' => $this->disk->exists($from) ? $this->disk->size($from) : 0,
        ]);
    }

    /**
     * Get the file size of a given file.
     */
    public function size($path): int
    {
        return $this->listener->trackFileOperation('size', $path, function() use ($path) {
            return $this->disk->size($path);
        }, ['disk' => $this->diskName]);
    }

    /**
     * Get the file's last modification time.
     */
    public function lastModified($path): int
    {
        return $this->listener->trackFileOperation('lastModified', $path, function() use ($path) {
            return $this->disk->lastModified($path);
        }, ['disk' => $this->diskName]);
    }

    /**
     * Get an array of all files in a directory.
     */
    public function files($directory = null, $recursive = false): array
    {
        return $this->listener->trackFileOperation('files', $directory ?: '/', function() use ($directory, $recursive) {
            return $this->disk->files($directory, $recursive);
        }, [
            'disk' => $this->diskName,
            'directory' => $directory ?: '/',
            'recursive' => $recursive,
        ]);
    }

    /**
     * Get all of the files from the given directory (recursive).
     */
    public function allFiles($directory = null): array
    {
        return $this->listener->trackFileOperation('allFiles', $directory ?: '/', function() use ($directory) {
            return $this->disk->allFiles($directory);
        }, [
            'disk' => $this->diskName,
            'directory' => $directory ?: '/',
        ]);
    }

    /**
     * Get all of the directories within a given directory.
     */
    public function directories($directory = null, $recursive = false): array
    {
        return $this->listener->trackFileOperation('directories', $directory ?: '/', function() use ($directory, $recursive) {
            return $this->disk->directories($directory, $recursive);
        }, [
            'disk' => $this->diskName,
            'directory' => $directory ?: '/',
            'recursive' => $recursive,
        ]);
    }

    /**
     * Get all of the directories within a given directory (recursive).
     */
    public function allDirectories($directory = null): array
    {
        return $this->listener->trackFileOperation('allDirectories', $directory ?: '/', function() use ($directory) {
            return $this->disk->allDirectories($directory);
        }, [
            'disk' => $this->diskName,
            'directory' => $directory ?: '/',
        ]);
    }

    /**
     * Create a directory.
     */
    public function makeDirectory($path): bool
    {
        return $this->listener->trackFileOperation('makeDirectory', $path, function() use ($path) {
            return $this->disk->makeDirectory($path);
        }, ['disk' => $this->diskName]);
    }

    /**
     * Recursively delete a directory.
     */
    public function deleteDirectory($directory): bool
    {
        return $this->listener->trackFileOperation('deleteDirectory', $directory, function() use ($directory) {
            return $this->disk->deleteDirectory($directory);
        }, ['disk' => $this->diskName]);
    }

    public function putFile($path, $file, $options = [])
    {
        return $this->listener->trackFileOperation('putFile', $path, function() use ($path, $file, $options) {
            return $this->disk->putFile($path, $file, $options);
        }, [
            'disk' => $this->diskName,
            'original_name' => method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : null,
            'size' => method_exists($file, 'getSize') ? $file->getSize() : 0,
        ]);
    }

    /**
     * Store the uploaded file on the disk.
     */
    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->listener->trackFileOperation('putFile', $path, function() use ($path, $file, $options) {
            return $this->disk->putFile($path, $file, $options);
        }, [
            'disk' => $this->diskName,
            'original_name' => method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : null,
            'size' => method_exists($file, 'getSize') ? $file->getSize() : 0,
        ]);
    }

    /**
     * Get the URL for the file at the given path.
     */
    public function url($path): string
    {
        return $this->disk->url($path);
    }

    /**
     * Get a temporary URL for the file at the given path.
     */
    public function temporaryUrl($path, $expiration, array $options = []): string
    {
        return $this->disk->temporaryUrl($path, $expiration, $options);
    }

    /**
     * Get a temporary upload URL for the file at the given path.
     */
    public function temporaryUploadUrl($path, $expiration, array $options = []): array
    {
        return $this->disk->temporaryUploadUrl($path, $expiration, $options);
    }

    /**
     * Get the full path to the file that exists at the given relative path.
     */
    public function path($path): string
    {
        return $this->disk->path($path);
    }

    /**
     * Get the JSON metadata for the file at the given path.
     */
    public function json($path, $flags = 0): array
    {
        return $this->listener->trackFileOperation('json', $path, function() use ($path, $flags) {
            return $this->disk->json($path, $flags);
        }, [
            'disk' => $this->diskName,
            'size' => $this->disk->exists($path) ? $this->disk->size($path) : 0,
        ]);
    }

    /**
     * Get a temporary URL for uploading files directly to the configured cloud provider.
     */
    public function buildTemporaryUrlsUsing(\Closure $callback): void
    {
        $this->disk->buildTemporaryUrlsUsing($callback);
    }

    /**
     * Handle dynamic method calls to the underlying disk.
     */
    public function __call($method, $parameters)
    {
        // For methods we don't explicitly handle, we can still track them
        return $this->listener->trackFileOperation($method, $parameters[0] ?? 'unknown', function() use ($method, $parameters) {
            return $this->disk->{$method}(...$parameters);
        }, [
            'disk' => $this->diskName,
            'method' => $method,
        ]);
    }
}
