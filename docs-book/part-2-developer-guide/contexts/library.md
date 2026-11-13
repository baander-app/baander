# Library

The Library context manages media libraries — collections of media files organized by type (`music`, `podcast`, `audiobook`, `movie`, `tv_show`). It handles library creation, file scanning for new or changed media, and user library access tracking. During a scan, each indexed directory is recorded in the transactional outbox as `LibraryDirectoryIndexed` and relayed to Catalog as a `FilesDiscovered` message on the `async` transport; when the scan finishes, `LibraryScanCompleted` is dispatched for Notification.

## Domain Models

### Aggregate Roots

| Model | Key Properties | Purpose |
|-------|---------------|---------|
| `Library` | `id`, `publicId`, `name`, `slug`, `path`, `type`, `filesystemType`, `sortOrder`, `lastScan`, `discoveryStatus`, `createdAt`, `updatedAt` | Represents a media library tied to a filesystem path (`LibraryState`) |

### Value Objects

| Model | Purpose |
|-------|---------|
| `LibraryPath` | Filesystem path with validation (must exist, must be readable) |
| `LibrarySlug` | URL-safe identifier derived from the library name |
| `LibraryType` | Enum: `Music` (`music`), `Podcast` (`podcast`), `Audiobook` (`audiobook`), `Movie` (`movie`), `TvShow` (`tv_show`) |
| `LibraryClaimKind` | Enum identifying what holds a library's exclusive lease: `Scan` (`scan`) or `Delete` (`delete`) |
| `LibraryClaimAttempt` | Outcome of claiming a library: claimed, held by a live `LibraryClaimKind`, or no such library |
| `LibraryClaimRelease` | Outcome of releasing a library's claim on an operator's request: released kind, no claim, or blocked by a live claim |

## Commands

| Command / Query | Handler | Purpose |
|---------|---------|---------|
| `CreateLibraryCommand` | `CreateLibraryHandler` | Creates a new library with a name, type, and filesystem path, validates the path, generates a slug, and optionally grants access to the creating user. |
| `UpdateLibraryCommand` | `UpdateLibraryHandler` | Updates a library's `name` and/or `sortOrder`. |
| `DeleteLibraryCommand` | `DeleteLibraryHandler` | Deletes a library by public ID or slug. |
| `StartLibraryScanCommand` | `StartLibraryScanHandler` | Claims a library for scanning and dispatches `ScanLibraryCommand` with that claim ID. |
| `ScanAllLibrariesCommand` | `ScanAllLibrariesHandler` | Claims every library not currently held by a live claim and dispatches `ScanLibraryCommand` for each. |
| `ClaimLibraryScanCommand` | `ClaimLibraryScanHandler` | Claims a single library for an inline scan run (`app:library:scan`). |
| `ClaimAllLibraryScansCommand` | `ClaimAllLibraryScansHandler` | Claims all unheld libraries for an inline `app:library:scan --all` run. |
| `ReleaseLibraryScanClaimCommand` | `ReleaseLibraryScanClaimHandler` | Releases a lapsed library claim (or a live one with `force`), marking a scan claim `failed` (`app:library:scan --release`). |
| `EndLibraryScanClaimCommand` | `EndLibraryScanClaimHandler` | Ends a specific scan claim by claim ID when an inline or unstarted scan cannot finish. |
| `ScanLibraryCommand` | `ScanLibraryHandler` | Walks the library's filesystem path via `LibraryDiscovery` (`MusicScanner` or `MovieScanner`) to discover new, changed, or removed media files. For each directory with new or changed files, commits the `library_file_index` upserts and dispatches `LibraryDirectoryIndexed` into the outbox in the same transaction (`LibraryDirectoryOutboxEventDispatcher` relays committed events as `FilesDiscovered` messages on the `async` transport). Dispatches `LibraryScanCompleted` when finished. A cancelled job stops before its next directory and marks the scan `failed`. |
| `GrantLibraryAccessCommand` | `GrantLibraryAccessHandler` | Grants a user access to a library (idempotent). |
| `RevokeLibraryAccessCommand` | `RevokeLibraryAccessHandler` | Revokes a user's access to a library (idempotent). |
| `ListLibrariesQuery` | `ListLibrariesHandler` | Lists libraries visible in a `LibraryReadScope`, optionally filtered by `LibraryType`. |
| `GetLibraryQuery` | `GetLibraryHandler` | Looks up a single visible library by public ID or slug. |
| `GetLibraryStatsQuery` | `GetLibraryStatsHandler` | Returns song, album, artist, genre, size, and duration totals for a visible library. |
| `ListLibraryAccessQuery` | `ListLibraryAccessHandler` | Lists all libraries in display order with whether a given user has access to each. |

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `LibraryPortInterface` | Library CRUD operations (create, read, update, delete, list) | `LibraryService` |
| `LibraryAccessPortInterface` | Grants, revokes, and queries user access to libraries | `LibraryAccessRepository` |
| `LibraryReadScopeProviderInterface` | Builds the `LibraryReadScope` for a user UUID or the current authenticated principal | `LibraryReadScopeProvider` (published as the Library Read Scope Contract) |
| `LibraryAudienceReader` | Lists library IDs readable by a principal for live-update snapshot scoping | `DoctrineLibraryAudienceReader` (published as the Library Live Audience Contract) |
| `DirectoryScannerPortInterface` | Walks a filesystem directory tree and returns discovered media files | `DirectoryScanner` |
| `LibraryMembershipQueryPort` | Queries which users belong to which libraries | `LibraryMembershipQuery` |
| `LibraryStatsQueryPort` | Per-library content statistics query port inside Library | `LibraryStatsQuery` |
| `LibraryContentStatsInterface` | Per-library song, album, artist, genre, size and duration totals | Catalog `CatalogStatsQuery` (published as the Library Content Stats Contract) |
| `LibraryProvisioningInterface` | Finds or creates a local movie library, scans it synchronously and returns `FilesDiscovered` messages for worker-free developer ingest | `LibraryProvisioner` (published as the Library Provisioning Contract; used by Catalog's `app:e2e:ingest-video`) |
| `LibraryMediaFilesInterface` | Checks and deletes the audio files of catalog entries being deleted, confined to their library's root | `LibraryMediaFiles` (published with its `LibraryMediaFile*` result types as the Library Media Files Contract; used by Catalog's album and song deletes and their previews) |
| `LibraryMediaFileResolverInterface` | Resolves an absolute catalog path to an existing file inside its owning library root | `LibraryMediaFileResolver` (part of the Library Media Files Contract; used by Media's `StreamService`) |
| `LibraryFileAvailabilityInterface` | Reads last-observed source file availability from `library_file_index` without filesystem checks | `LibraryFileAvailability` (published as the Library File Availability Contract) |

## Media File Deletion

`LibraryMediaFilesInterface` deletes the audio files of catalog entries that are being deleted, confined to their library's root. It is the single guard for that deletion, so the API and the console apply the same checks. A path is the absolute path the scanner stored. A caller uses the port in five steps, and releases the claim in a `finally` block:

1. `claim()`, before anything else, claims the library for the delete with a claim of the kind `delete`, the same lease a scan claims, so no scan indexes the files while the delete runs. It throws `LibraryBusyException` (409, reason `library_busy`, with the holder's kind) while a scan or another delete holds a live claim. The claim leaves the library's scan status and last scan time alone; a lapsed scan claim it takes over belonged to a scan that died, which it marks `failed`. Before it claims, it waits for the import writes in flight on the library to commit (see below), so a caller that reads its songs after `claim()` reads every song the import wrote.
2. `prepareDeletion()`, before the transaction, checks every path under the delete's own claim and refuses the whole request before anything changes: `LibraryRootUnavailableException` (409) when the library root is not an existing directory (unmounted storage would otherwise read as every file missing, and dropping their index rows would let the next scan import them again), `LibraryMediaFileOutsideRootException` (422) when a path, or the file a symlink at it points to, lies outside the library root, `LibraryMediaFilesAllMissingException` (409, reason `all_files_missing`) when the request has paths and every one reads as missing, as when the storage under part of the library is not mounted (a request without paths, such as an album without songs, is not refused for this), and `LibraryMediaDirectoryNotWritableException` (409) when the server cannot write a directory that holds one of the files. A delete preview calls `inspect()` instead, which runs the same checks, reports whether a scan or another delete holds the library, and changes nothing.
3. `deleteIndexRows()`, inside the caller's transaction, deletes the `library_file_index` rows of the paths through `LibraryFileIndexRepositoryInterface::removeByPaths()`, up to 1,000 paths per statement. It throws a `LogicException` when no transaction is active, so the index rows go only in the transaction that deletes the catalog rows.
4. `deleteFiles()`, after the commit, renews the claim's lease (once per minute at most), checks each file again and unlinks it. A symlink is removed as a link and its target is kept. A file that now resolves outside the root, or cannot be unlinked, is reported as left in `LibraryMediaFileDeletionResult`. When the renewal finds the claim taken over, which happens only after the delete went past its lease, the files not yet unlinked are left with `claim_lost`.
5. `release()` ends the claim, also when a step before failed. A failure to end the claim is retried once, then logged instead of thrown, so it never hides the delete's own outcome; the claim then lapses with its lease. The console delete commands turn the first SIGINT or SIGTERM of a delete into an exception inside the running delete, so this step runs for them too. Later signals of the same delete are ignored, so they cannot cut the release short, and the retry ends a claim whose first release attempt the interruption cut short. When a catch inside the delete swallows the interruption, the delete finishes and the command prints its result with a warning naming the signal.

With its index row gone, a file the deletion leaves on disk reads as new to the next incremental scan and is imported again.

`MediaFileGuard` checks each file again immediately before unlinking it. PHP has no `unlinkat()`, so a parent directory replaced with a symlink between that check and the unlink is not caught. This is an accepted limit, documented for operators on the delete command pages; only a user who can write the library's directories can cause it.

Catalog's `FilesDiscoveredHandler` also uses the port when it imports a directory:

- `isHeldByDelete()` is true while a delete claim holds the library. Before it starts, the handler then puts the message back on the `async` transport with a 30-second delay and imports nothing, so it cannot import a file the delete is about to unlink (a scan only drops index rows, so such a song would stay in the catalog). The claim lapses with its lease if the delete dies, so the wait ends.
- `markMissingUnavailable()` names paths with no file now and marks their retained `library_file_index` rows unavailable. The handler skips these paths before resolving the album or probing a video, so a directory whose files are all gone creates no album or movie. A later scan restores availability and queues unchanged returning files again.
- `isHeldByDeleteForImport()` guards each write. The handler reads the metadata of up to 50 files, then writes their songs, artist links and genres in one transaction (`TransactionPortInterface`) that first calls this method. It takes `pg_advisory_xact_lock_shared` on a key derived from the library ID and then reads the claim; `claimDelete()` takes `pg_advisory_xact_lock` on the same key in the transaction of its claim statement. A delete claim therefore waits for the writes in flight to commit, and every later write sees the claim. A write that sees it writes nothing; the handler puts the files whose songs it has not committed back on the queue with the same delay, and the songs already committed stay. The key is `hashtextextended(<library ID>, 20261010120000)`; the seed keeps it apart from the application's other advisory locks, and a hash collision only makes two libraries wait for each other. The read of the claim is a statement of its own, so under PostgreSQL's default READ COMMITTED isolation its snapshot is taken after the lock is granted. A scan claim takes no import lock.

`findAvailableHashesByPaths()` reads the current available observations for the
requested library and paths. Ingestion filters queued files against these hashes
before checking presence or reading metadata. Missing, unavailable and mismatched
observations are skipped. `lockAvailableHashesByPathsForImport()` repeats the read
with `FOR SHARE` inside an active transaction, after the shared import/delete
guard. Album and movie parent writes, song and video writes, and source rename
reconciliation use this boundary. Metadata reads and FFprobe run before the locks.
If an album's metadata source becomes superseded during preparation, the handler
reselects from the remaining current candidates; each failed selection removes at
least one candidate. Current files in a mixed message still import.

The index locks use bytewise path order across bounded SQL statements. Scanners
sort a copy for their database upserts while preserving result and event order.
Bulk unavailable/removal operations lock existing target rows in the same order
and mutate only those locked paths, so a path inserted after the lock query is not
changed by that older operation. Import locks hold the matched observations stable
through commit or rollback. Hash equality permits a latest observation that returns
to an earlier hash; this is a committed-index check, not a live filesystem snapshot.

Each path gets a `LibraryMediaFileVerdict`: `deletable`, `missing` (nothing at the path, or a symlink to nothing; only the index row goes), `outside_root`, or `directory_not_writable`. `LibraryRepositoryInterface::liveClaimKind()` answers which kind of claim, if any, holds the library without having lapsed, by the database clock (`clock_timestamp()`); `liveClaimKindForImport()` answers the same after taking the import lock, and throws a `LogicException` outside a transaction, where the lock would end with its own statement.

## Domain Events

| Event | When Emitted | Consumers |
|-------|-------------|------------|
| `LibraryDirectoryIndexed` | Captured in the transactional outbox when a scanner commits a directory's `library_file_index` upserts | Relayed by `LibraryDirectoryOutboxEventDispatcher` as `FilesDiscovered` on the `async` transport for Catalog (`FilesDiscoveredHandler`) |
| `LibraryScanCompleted` | After a library scan finishes discovering files | Notification (BackgroundJobs category) |

## API Endpoints

All endpoints are prefixed with `/api`. Routes under `/api/libraries` are served by `LibraryController`; user library access routes under `/api/admin/users/{userId}/libraries` are served by `AdminUserLibraryAccessController`.

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/libraries` | List libraries the authenticated user has access to |
| `POST` | `/api/libraries` | Create a new library (administrators only) |
| `POST` | `/api/libraries/validate-path` | Check that a server path exists and is readable (administrators only) |
| `POST` | `/api/libraries/scan-all` | Claim all unheld libraries and queue a background scan for each (administrators only) |
| `GET` | `/api/libraries/{id}` | Get a single library by ID |
| `PATCH` | `/api/libraries/{id}` | Update library metadata (`name`, `sortOrder` via `UpdateLibraryRequest`; administrators only) |
| `DELETE` | `/api/libraries/{id}` | Delete a library and its membership records (administrators only) |
| `POST` | `/api/libraries/{id}/scan` | Claim a library and queue an asynchronous scan job, with optional `rescan` flag (administrators only) |
| `GET` | `/api/libraries/{id}/stats` | Get song, album, artist, genre, size, and duration totals for a visible library |
| `GET` | `/api/admin/users/{userId}/libraries` | List every library in display order with whether the user has access (`ROLE_ADMIN` + `USER_MANAGEMENT_LIST`) |
| `PUT` | `/api/admin/users/{userId}/libraries/{libraryId}` | Grant the user access to a library (`ROLE_SUPER_ADMIN`) |
| `DELETE` | `/api/admin/users/{userId}/libraries/{libraryId}` | Revoke the user's access to a library (`ROLE_SUPER_ADMIN`) |

### Console Commands

| Command | Class | Purpose |
|---------|-------|---------|
| `app:library:list` | `LibraryListCommand` | List every media library, optionally filtered by `--type` |
| `app:library:show` | `LibraryShowCommand` | Show details for one media library by public ID or slug |
| `app:library:create` | `CreateLibraryCommand` | Create a new media library |
| `app:library:update` | `LibraryUpdateCommand` | Rename a media library (`--name`) or change its sort order (`--sort-order`) |
| `app:library:delete` | `LibraryDeleteCommand` | Delete a media library |
| `app:library:scan` | `ScanLibraryCommand` | Scan one library or `--all` inline in the CLI process (with optional `--rescan`), or release a claim with `--release` (`--force`) |
| `app:library:stats` | `LibraryStatsCommand` | Show content counts, size, and duration for a media library |
| `app:library:validate-path` | `LibraryValidatePathCommand` | Check that a directory path exists, is readable, and does not overlap another library |
| `app:library:member:list` | `LibraryMemberListCommand` | List every library with whether a user (by email or UUID) has access |
| `app:library:member:grant` | `LibraryMemberGrantCommand` | Grant a user access to a library |
| `app:library:member:revoke` | `LibraryMemberRevokeCommand` | Revoke a user's access to a library |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `DirectoryScanner` | Recursively walks a filesystem path, filtering for media file extensions. Returns a collection of `MediaFile` objects. |
| `MediaFile` | Value object representing a discovered file (path, size, MIME type, modification time). |
| `LibraryMediaFiles` | Implements `LibraryMediaFilesInterface`: loads the library, takes, renews and releases the delete claim through the repository, and delegates the file system checks to `MediaFileGuard`. |
| `LibraryMediaFileResolver` | Implements `LibraryMediaFileResolverInterface`: verifies a catalog path against its owning library root using `MediaFileGuard`. |
| `MediaFileGuard` | The file system rules of media file deletion: resolves symlinks in parent directories, checks that the entry and a symlink's target lie under the root's real path, and checks again immediately before each unlink. |
| `PathSecurityService` | Validates filesystem paths to prevent directory traversal attacks. Ensures resolved paths stay within allowed boundaries. |
| `LibraryService` | Implements `LibraryPortInterface` via `LibraryRepositoryInterface`. |
| `LibraryReadScopeProvider` | Implements `LibraryReadScopeProviderInterface` using `AuthenticatedUserIdentityInterface`, `CurrentUserAuthorityReader`, and `LibraryAccessPortInterface`. |
| `DoctrineLibraryAudienceReader` | Implements `LibraryAudienceReader` for live-update audience resolution. |
| `LibraryFileAvailability` | Implements `LibraryFileAvailabilityInterface` via `LibraryFileIndexRepositoryInterface`. |
| `LibraryDirectoryOutboxEventDispatcher` | Outbox event dispatcher decorator that converts committed `LibraryDirectoryIndexed` events into `FilesDiscovered` messages on the `async` transport. |
| `LibraryMessagePayloadCodec` | Encodes `ScanLibraryCommand` and `FilesDiscovered` for the `async` transport and job monitor. |
| `LibraryEntity` | Doctrine ORM entity for the `libraries` table. |
| `LibraryFileIndexEntity` | Doctrine ORM entity for the `library_file_index` table. |
| `UserLibraryAccessEntity` | Doctrine ORM entity for the `user_library_access` table (user-to-library access grants). |
| `LibraryAccessRepository` | Implements `LibraryAccessPortInterface` against `user_library_access`. |
| `LibraryMembershipQuery`, `LibraryStatsQuery` | Implement `LibraryMembershipQueryPort` and `LibraryStatsQueryPort`. |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | Uses `Uuid`, `PublicId`, `FilesystemType`, `LibraryReadScope`, transactional outbox, and job-monitor ports |
| Depends on | Auth | Uses `AuthenticatedUserIdentityInterface`, `CurrentUserAuthorityReader`, and `UserIdentifierResolverInterface` for read-scope resolution and member access commands |
| Depended on by | Catalog | Consumes `FilesDiscovered`; implements `LibraryContentStatsInterface`; uses `LibraryProvisioningInterface`, `LibraryFileAvailabilityInterface`, `LibraryReadScopeProviderInterface`, and `LibraryMediaFilesInterface` (album and song deletes and their previews, and `FilesDiscoveredHandler` to wait for a delete and skip missing files) |
| Depended on by | Activity, Lyrics, Media, Metadata, Notification, Playlist, Shared | Use `LibraryReadScopeProviderInterface`, `LibraryMediaFileResolverInterface` (Media), `LibraryPortInterface` (Metadata), `LibraryMembershipQueryPort` (Notification), and `LibraryAudienceReader` (Shared live snapshots) |
