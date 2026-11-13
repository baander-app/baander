# Filesystem

The Filesystem context provides file operation utilities: MIME type detection from magic bytes, boundary-checked local and read-only file access, and directory watching via inotify. It is a utility context with no domain layer, no HTTP endpoints, and no database entities.

## Layer Structure

This context has Application and Infrastructure layers alongside its `Command`, `Mime`, and `Watcher` utility namespaces. There is no Domain layer and no HTTP Interface layer.

## Commands

| Command | Handler | Purpose |
|---------|---------|---------|
| `WatchFilesCommand` | Self-handling (`app:watch-files`) | Starts an inotify-based file watcher that monitors directory paths passed via `--path` (`-p`), or the current working directory if none are given, for filesystem events (create, modify, delete, move, close-write) |

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `MimeDetectorPortInterface` | Detects MIME type from file content (magic bytes rather than file extension); used by Catalog (`SetCoverHandler`) | `MimeDetector` |
| `LocalFilesystemPortInterface` | Resolves relative paths inside a base directory and performs coroutine-aware reads, writes, and streaming opens | `LocalFilesystem` |
| `ReadOnlyFilesystemPortInterface` | Read-only filesystem operations (`resolve`, `exists`, `size`, `read`, `open`, `list`, `isDirectory`, `isFile`, `lastModified`) confined to a base directory; only `'r'` and `'rb'` open modes are accepted | `ReadOnlyFilesystem` |
| `FileHandle` | Coroutine-aware file handle wrapping `fopen` with `Swoole\Coroutine\System::fread`, `fwrite`, and `fgets` | Direct value/helper class in `Application\Port` |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `MimeDetector` | Reads file content (magic bytes) to determine MIME type without relying on file extensions. |
| `LocalFilesystem` | Implements `LocalFilesystemPortInterface` using `StoragePathBoundary` and `Swoole\Coroutine\System::readFile`/`writeFile`. |
| `ReadOnlyFilesystem` | Wraps `LocalFilesystemPortInterface` and enforces read-only access within a resolved base directory. |
| `ReadOnlyFilesystemFactory` | Creates a `ReadOnlyFilesystemPortInterface` instance scoped to a base path for a given `FilesystemType`. |
| `FileWatcher` | Wraps Linux inotify to monitor directories for filesystem events as a long-lived process. |
| `FileWatchEvent` | Value object representing a single filesystem change event (watch descriptor, path, full path, event mask, directory flag). |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | Uses `StoragePathBoundary` for path confinement, `FilesystemType` in `ReadOnlyFilesystemFactory`, and `Async::sleep()` in `FileWatcher` |
| Depended on by | Catalog | `SetCoverHandler` uses `MimeDetectorPortInterface` to verify uploaded cover image MIME types |
