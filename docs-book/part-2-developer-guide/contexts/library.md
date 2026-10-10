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
| `ScanLibraryCommand` | `ScanLibraryHandler` | Walks the library's filesystem path to discover new, changed, or removed media files, and publishes `FilesDiscovered` for each directory with new or changed files. Dispatches `LibraryScanCompleted` when finished. A cancelled job stops before its next directory and marks the scan `failed`. |

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `LibraryPortInterface` | Library CRUD operations (create, read, update, delete, list) | Doctrine repository |
| `DirectoryScannerPortInterface` | Walks a filesystem directory tree and returns discovered media files | `DirectoryScanner` |
| `CoverArtExtractorPortInterface` | Extracts embedded cover art from media files (e.g., ID3 tags in MP3s) | `CoverArtExtractor` |
| `LibraryMembershipQueryPort` | Queries which users belong to which libraries | Doctrine repository |
| `LibraryContentStatsInterface` | Per-library song, album, artist, genre, size and duration totals | Catalog `CatalogStatsQuery` (published as the Library Content Stats Contract) |
| `LibraryProvisioningInterface` | Finds or creates a local movie library, scans it synchronously and returns `FilesDiscovered` messages for worker-free developer ingest | `LibraryProvisioner` (published as the Library Provisioning Contract; used by Catalog's `app:e2e:ingest-video`) |
| `LibraryMediaFilesInterface` | Checks and deletes the audio files of catalog entries being deleted, confined to their library's root | `LibraryMediaFiles` (published with its `LibraryMediaFile*` result types as the Library Media Files Contract; used by Catalog's album and song deletes and their previews) |

## Media File Deletion

`LibraryMediaFilesInterface` deletes the audio files of catalog entries that are being deleted, confined to their library's root. It is the single guard for that deletion, so the API and the console apply the same checks. A path is the absolute path the scanner stored. A caller uses the port in five steps, and releases the claim in a `finally` block:

1. `claim()`, before anything else, claims the library for the delete with a claim of the kind `delete`, the same lease a scan claims, so no scan indexes the files while the delete runs. It throws `LibraryBusyException` (409, reason `library_busy`, with the holder's kind) while a scan or another delete holds a live claim. The claim leaves the library's scan status and last scan time alone; a lapsed scan claim it takes over belonged to a scan that died, which it marks `failed`.
2. `prepareDeletion()`, before the transaction, checks every path under the delete's own claim and refuses the whole request before anything changes: `LibraryRootUnavailableException` (409) when the library root is not an existing directory (unmounted storage would otherwise read as every file missing, and dropping their index rows would let the next scan import them again), `LibraryMediaFileOutsideRootException` (422) when a path, or the file a symlink at it points to, lies outside the library root, `LibraryMediaFilesAllMissingException` (409, reason `all_files_missing`) when the request has paths and every one reads as missing, as when the storage under part of the library is not mounted (a request without paths, such as an album without songs, is not refused for this), and `LibraryMediaDirectoryNotWritableException` (409) when the server cannot write a directory that holds one of the files. A delete preview calls `inspect()` instead, which runs the same checks, reports whether a scan or another delete holds the library, and changes nothing.
3. `deleteIndexRows()`, inside the caller's transaction, deletes the `library_file_index` rows of the paths through `LibraryFileIndexRepositoryInterface::removeByPaths()`, up to 1,000 paths per statement. It throws a `LogicException` when no transaction is active, so the index rows go only in the transaction that deletes the catalog rows.
4. `deleteFiles()`, after the commit, renews the claim's lease (once per minute at most), checks each file again and unlinks it. A symlink is removed as a link and its target is kept. A file that now resolves outside the root, or cannot be unlinked, is reported as left in `LibraryMediaFileDeletionResult`. When the renewal finds the claim taken over, which happens only after the delete went past its lease, the files not yet unlinked are left with `claim_lost`.
5. `release()` ends the claim, also when a step before failed. It logs a failure to end the claim instead of throwing, so it never hides the delete's own outcome; the claim then lapses with its lease. The console delete commands turn SIGINT and SIGTERM into an exception inside the running delete, so this step runs for them too.

With its index row gone, a file the deletion leaves on disk reads as new to the next incremental scan and is imported again.

`MediaFileGuard` checks each file again immediately before unlinking it. PHP has no `unlinkat()`, so a parent directory replaced with a symlink between that check and the unlink is not caught. This is an accepted limit, documented for operators on the delete command pages; only a user who can write the library's directories can cause it.

Catalog's `FilesDiscoveredHandler` asks the port two more questions before it imports a directory. `isHeldByDelete()` is true while a delete claim holds the library; the handler then puts the message back on the `async` transport with a 30-second delay and imports nothing, so it cannot import a file the delete is about to unlink (a scan only drops index rows, so such a song would stay in the catalog). The claim lapses with its lease if the delete dies, so the wait ends. `missingPaths()` names the paths with no file now; the handler drops them before it resolves the album or probes a video, so a directory whose files are all gone creates no album or movie.

Each path gets a `LibraryMediaFileVerdict`: `deletable`, `missing` (nothing at the path, or a symlink to nothing; only the index row goes), `outside_root`, or `directory_not_writable`. `LibraryRepositoryInterface::liveClaimKind()` answers which kind of claim, if any, holds the library without having lapsed, by the database clock (`clock_timestamp()`).

## Domain Events

| Event | When Emitted | Consumers |
|-------|-------------|------------|
| `LibraryScanCompleted` | After a library scan finishes discovering files | Notification (BackgroundJobs category) |

## API Endpoints

All endpoints are prefixed with `/api` and served by `LibraryController`.

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/libraries` | List libraries the authenticated user has access to |
| `POST` | `/api/libraries` | Create a new library (administrators only) |
| `POST` | `/api/libraries/validate-path` | Check that a server path exists and is readable (administrators only) |
| `GET` | `/api/libraries/{id}` | Get a single library by ID |
| `PATCH` | `/api/libraries/{id}` | Update library metadata (name, path) |
| `DELETE` | `/api/libraries/{id}` | Delete a library and its membership records |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `DirectoryScanner` | Recursively walks a filesystem path, filtering for media file extensions. Returns a collection of `MediaFile` objects. |
| `MediaFile` | Value object representing a discovered file (path, size, MIME type, modification time). |
| `LibraryMediaFiles` | Implements `LibraryMediaFilesInterface`: loads the library, takes, renews and releases the delete claim through the repository, and delegates the file system checks to `MediaFileGuard`. |
| `MediaFileGuard` | The file system rules of media file deletion: resolves symlinks in parent directories, checks that the entry and a symlink's target lie under the root's real path, and checks again immediately before each unlink. |
| `PathSecurityService` | Validates filesystem paths to prevent directory traversal attacks. Ensures resolved paths stay within allowed boundaries. |
| `LibraryEntity` | Doctrine ORM entity for the `libraries` table. |
| `UserLibraryEntity` | Doctrine ORM entity for the `user_libraries` junction table (many-to-many relationship between users and libraries). |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | Uses `Uuid` and `PublicId` for entity identification |
| Depends on | Filesystem | Uses `MimeDetectorPortInterface` during scans and `FileWatcher` to detect filesystem changes |
| Depended on by | Catalog | Album and song deletes and their previews use `LibraryMediaFilesInterface`; `FilesDiscoveredHandler` uses it to wait for a delete and skip missing files |
