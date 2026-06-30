# Library

The Library context manages media libraries -- collections of media files organized by type (music, movies, video). It handles library creation, file scanning for new or changed media, and membership tracking (which users belong to which libraries). When a scan completes, it dispatches domain events that the Metadata context picks up for enrichment.

## Domain Models

### Aggregate Roots

| Model | Key Properties | Purpose |
|-------|---------------|---------|
| `Library` | name, type, path, slug, owner | Represents a media library tied to a filesystem path |

### Value Objects

| Model | Purpose |
|-------|---------|
| `LibraryPath` | Filesystem path with validation (must exist, must be readable) |
| `LibrarySlug` | URL-safe identifier derived from the library name |
| `LibraryType` | Enum: `Music`, `Movie`, `Video` |

## Commands

| Command | Handler | Purpose |
|---------|---------|---------|
| `CreateLibraryCommand` | `CreateLibraryHandler` | Creates a new library with a name, type, and filesystem path. Validates the path and generates a slug. |
| `ScanLibraryCommand` | `ScanLibraryHandler` | Walks the library's filesystem path to discover new, changed, or removed media files. Dispatches `LibraryScanCompleted` when finished. |

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `LibraryPortInterface` | Library CRUD operations (create, read, update, delete, list) | Doctrine repository |
| `DirectoryScannerPortInterface` | Walks a filesystem directory tree and returns discovered media files | `DirectoryScanner` |
| `CoverArtExtractorPortInterface` | Extracts embedded cover art from media files (e.g., ID3 tags in MP3s) | `CoverArtExtractor` |
| `LibraryMembershipQueryPort` | Queries which users belong to which libraries | Doctrine repository |

## Domain Events

| Event | When Emitted | Consumers |
|-------|-------------|------------|
| `LibraryScanCompleted` | After a library scan finishes discovering files | Metadata context (triggers enrichment for newly discovered files) |

## API Endpoints

All endpoints are prefixed with `/api` and served by `LibraryController`.

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/libraries` | List libraries the authenticated user has access to |
| `POST` | `/api/libraries` | Create a new library |
| `GET` | `/api/libraries/{id}` | Get a single library by ID |
| `PATCH` | `/api/libraries/{id}` | Update library metadata (name, path) |
| `DELETE` | `/api/libraries/{id}` | Delete a library and its membership records |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `DirectoryScanner` | Recursively walks a filesystem path, filtering for media file extensions. Returns a collection of `MediaFile` objects. |
| `MediaFile` | Value object representing a discovered file (path, size, MIME type, modification time). |
| `PathSecurityService` | Validates filesystem paths to prevent directory traversal attacks. Ensures resolved paths stay within allowed boundaries. |
| `LibraryEntity` | Doctrine ORM entity for the `libraries` table. |
| `UserLibraryEntity` | Doctrine ORM entity for the `user_libraries` junction table (many-to-many relationship between users and libraries). |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | Uses `Uuid` and `PublicId` for entity identification |
| Depends on | Filesystem | Uses `MimeDetectorPortInterface` during scans and `FileWatcher` to detect filesystem changes |
| Depended on by | Metadata | Receives scan results via `LibraryScanCompleted` events for metadata enrichment |
