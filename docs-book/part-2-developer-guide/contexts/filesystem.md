# Filesystem

The Filesystem context provides file operation utilities: MIME type detection for media files and file system watching via inotify. It is a pure utility context with no domain layer, no HTTP interface, and no database entities. Other contexts depend on it when they need to identify file types or react to filesystem changes.

## Layer Structure

This context has only Application and Infrastructure layers. There is no Domain layer (no business rules to model) and no Interface layer (no HTTP endpoints).

## Commands

| Command | Handler | Purpose |
|---------|---------|---------|
| `WatchFilesCommand` | Self-handling | Starts an inotify-based file watcher that monitors configured library paths for filesystem events (create, modify, delete, move) |

## Ports

| Port | Purpose | Used By |
|------|---------|---------|
| `MimeDetectorPortInterface` | Detects MIME type from file content (not file extension) | Library context during scans |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `MimeDetector` | Reads file content (magic bytes) to determine MIME type. Does not rely on file extensions, which makes it reliable for user-uploaded files where extensions may be missing or wrong. |
| `FileWatcher` | Wraps Linux inotify to monitor directories for filesystem events. Runs as a long-lived process. |
| `FileWatchEvent` | Value object representing a single filesystem change event (path, event type). |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | None | Pure utility with no outbound dependencies |
| Depended on by | Library | Uses `MimeDetectorPortInterface` during file scanning and `FileWatcher` to detect new or changed files |
