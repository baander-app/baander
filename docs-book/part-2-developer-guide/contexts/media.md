# Media

The Media context handles media file operations: image storage (album art, artist art, playlist art), WebP derivative conversion, BlurHash generation for placeholder thumbnails, playback delivery negotiation, and media file streaming. It provides the storage and playback-access abstractions that other contexts use when persisting or serving binary files.

## Domain Models

### Aggregate Roots

| Model | Key Properties | Purpose |
|-------|---------------|---------|
| `Image` | `id`, `publicId`, `path`, `extension`, `mimeType`, `blurhash`, `size`, `width`, `height`, `imageableType`, `albumId`, `artistId`, `playlistId`, `createdAt`, `updatedAt` | Represents a stored image with dimensions, BlurHash, and owner association (`ImageState`) |

### Value Objects & Read Models

| Model | Key Properties | Purpose |
|-------|---------------|---------|
| `StoredFile` | `path`, `mimeType`, `size` | Value object representing a file stored on disk |
| `TrackStreamMetadata` | `publicId`, `filename`, `filePath`, `mimeType`, `size`, `codec`, `bitrate`, `sampleRate`, `channels`, `length`, `containerFormat`, `streamIndex`, `sourceHash` | Metadata for a streamed audio track |
| `ImageReadView` | `id`, `publicId`, `path`, `extension`, `mimeType`, `blurhash`, `size`, `width`, `height`, `imageableType`, `albumPublicId`, `artistPublicId`, `playlistPublicId`, `createdAt`, `updatedAt` | Read model returned for visible images with visibility-filtered owner public IDs |

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `GenerateImageDerivativeCommand` | `GenerateImageDerivativeHandler` | Generates a WebP conversion or size preset (`thumb`, `small`, `medium`, `large`) on the `async` transport via `ImageDerivativePortInterface` |
| `PruneMissingImagesCommand` | `PruneMissingImagesHandler` | Scans stored `Image` records in batches and deletes records whose backing files no longer exist in `StoragePortInterface` |

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `ImagePortInterface` | Image CRUD and visibility-scoped read/BlurHash operations | `ImageService` |
| `StoragePortInterface` | Abstract file storage for binary data (`store`, `storeFromStream`, `read`, `readAsStream`, `delete`, `exists`, `size`, `mimeType`, `resolve`) | `FlysystemStorage` (Flysystem adapter) |
| `StreamPortInterface` | Track path resolution, `TrackStreamMetadata` lookup, and library ID lookup for a track | `StreamService` |
| `DeliveryDecisionPortInterface` | Chooses compatible playback delivery (`Original`, `Copy`, `Cached`, `Encoded`, `Incompatible`) for video and audio tracks | `DeliveryDecisionService` |
| `PlaybackMediaAccessInterface` | Asserts that a user has library access to a track or video (`Media Playback Access Contract`) | `PlaybackMediaAccess` |
| `MediaReadScopeProviderInterface` | Builds the `MediaReadScope` for a user UUID or the current authenticated principal | `MediaReadScopeProvider` |
| `ImageConversionPortInterface` | Converts images to WebP and responsive WebP size presets | `GdImageConverter` |
| `ImageDerivativePortInterface` | Generates locked, atomic WebP derivatives in storage | `ImageDerivativeGenerator` |
| `MediaAdminPortInterface` | Image storage statistics, missing-file dry-run checks, and pruning dispatch for admin endpoints and CLI commands | `MediaAdminService` |

## API Endpoints

All endpoints are prefixed with `/api`.

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| `GET` | `/api/images/{publicId}` | `ImageController` | Get image metadata (dimensions, MIME type, size) |
| `GET` | `/api/images/{publicId}/file` | `ImageController` | Serve the image file binary (or a cached WebP derivative / `preset`) |
| `GET` | `/api/images/{publicId}/blurhash` | `ImageController` | Get the BlurHash placeholder string for progressive image loading |
| `GET` | `/api/stream/track` | `StreamController` | Stream a track by public ID after checking library access; supports byte ranges. With `format` (`opus`, `aac` or `mp3`) and an optional `bitrate` in bits per second, streams a cached transcoded rendition while `transcode.enabled` is on (see [Transcode](transcode.md#audio-renditions)) |
| `POST` | `/api/stream/delivery` | `DeliveryController` | Choose a compatible delivery mode (`original`, `copy`, `cached`, `encoded`, `incompatible`) and signed URL for a video or track |
| `GET, HEAD` | `/api/stream/video` | `OriginalMediaController` | Deliver an authorized original video with byte-range support |
| `GET, HEAD` | `/api/stream/original/track` | `OriginalMediaController` | Deliver authorized original track audio, stream-copied AAC (`mode=copy`), or a completed cached audio rendition with byte-range support |
| `GET` | `/api/admin/media/storage-stats` | `MediaAdminController` | Get image counts and stored byte sizes grouped by owner type (`ROLE_ADMIN`) |
| `POST` | `/api/admin/media/prune-missing` | `MediaAdminController` | Dispatch `PruneMissingImagesCommand` to remove image records with missing files (`ROLE_ADMIN`) |
| `GET` | `/api/admin/media/missing-check` | `MediaAdminController` | Dry-run check listing image records whose backing files are missing (`ROLE_ADMIN`) |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `FlysystemStorage` | Abstract filesystem storage via Flysystem. Provides a unified API regardless of whether files are stored on local disk, S3, or another adapter. |
| `ImageService` | Implements `ImagePortInterface` over `ImageRepositoryInterface`. |
| `StreamService` | Implements `StreamPortInterface` using Catalog's `SongRepositoryInterface` and Library's `LibraryMediaFileResolverInterface`. |
| `MediaReadScopeProvider` | Implements `MediaReadScopeProviderInterface` using Auth's `AuthenticatedUserIdentityInterface` and Library's `LibraryReadScopeProviderInterface`. |
| `MediaAdminService` | Implements `MediaAdminPortInterface` for storage stats, missing-image checks, and pruning job dispatch. |
| `BlurHashGenerator` | Generates a compact BlurHash string from an image. Used by the frontend to render a low-fidelity placeholder while the full image loads. |
| `GdImageConverter` | Implements `ImageConversionPortInterface` using GD to publish WebP files and responsive presets (`thumb`, `small`, `medium`, `large`) atomically. |
| `ImageDerivativeGenerator` | Implements `ImageDerivativePortInterface` with file locking (`flock`) around `GdImageConverter`. |
| `ImageConverter` | Legacy GD image converter (`@deprecated` in favor of `ImageConversionPortInterface` via `GdImageConverter`). |
| `MediaMessagePayloadCodec` | Encodes `GenerateImageDerivativeCommand` and `PruneMissingImagesCommand` for the `async` transport and job monitor. |
| `ImageEntity` | Doctrine ORM entity for the `images` table. |
| `PruneMissingImagesCommand` | Console command (`app:image:prune-missing`) that removes image records whose backing files no longer exist in storage, or lists them with `--dry-run`. |
| `ImageStatsCommand` | Console command (`app:image:stats`) that shows image counts and stored sizes by owner type. |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | Uses `Uuid`, `PublicId`, `MediaReadScope`, `JobMonitorAdministrationInterface`, and `SystemSettingsPortInterface` for the transcoding settings |
| Depends on | Auth | `MediaReadScopeProvider` resolves the current user through `AuthenticatedUserIdentityInterface` |
| Depends on | Catalog | `DeliveryDecisionService`, `PlaybackMediaAccess`, and `OriginalMediaController` use `VideoPortInterface`; `StreamService` reads songs via `SongRepositoryInterface`; `ImageEntity` and `ImageRepository` join `AlbumEntity` and `ArtistEntity` |
| Depends on | Library | `MediaReadScopeProvider` uses `LibraryReadScopeProviderInterface`; `StreamService` resolves track files inside their library root through `LibraryMediaFileResolverInterface` |
| Depends on | Party | Watch-party playback shares the video playback access rules enforced across `Media`, `Transcode`, and `Party` |
| Depends on | Playlist | `ImageEntity` and `ImageRepository` join `PlaylistEntity` to scope playlist cover visibility |
| Depends on | Transcode | `StreamController`, `OriginalMediaController`, `DeliveryController`, `DeliveryDecisionService`, and `DeliveryCompatibility` use `AudioRenditionPortInterface` (the `Transcode Audio Rendition Contract` Deptrac layer), `MediaExecutionPolicyInterface`, `CompletedVideoArtifactPort`, `CompletedVideoDeliveryPort`, `CopyPresentationPortInterface`, and `StreamAuthPortInterface` |
| Depended on by | Catalog | Stores and reads cover art images for albums and artists via `ImagePortInterface` and `StoragePortInterface` (`Media Image Contract`) |
| Depended on by | Metadata | `ExtractAlbumCoverHandler` stores extracted album cover art via `ImagePortInterface`, `StoragePortInterface`, and `Image` |
| Depended on by | Session | `PlaybackCoordinator` verifies track and video access via `PlaybackMediaAccessInterface` (`Media Playback Access Contract`) |
| Depended on by | Transcode | `CompletedVideoDeliveryAdapter` checks video playback access via `PlaybackMediaAccessInterface` (`Media Playback Access Contract`) |
| Depended on by | Activity, Recommendation | Enrich activity and recommendation items with cover art via `ImagePortInterface` |
