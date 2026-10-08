# Media

The Media context handles media file operations: image storage (album art, user uploads), image format conversion, BlurHash generation for placeholder thumbnails, and media file streaming. It provides the storage abstraction that other contexts use when they need to persist or serve binary files.

## Domain Models

### Aggregate Roots

| Model | Key Properties | Purpose |
|-------|---------------|---------|
| `Image` | public ID, original filename, MIME type, dimensions, size | Represents a stored image with metadata (album art, user avatars, uploaded images) |
| `StoredFile` | public ID, filename, MIME type, size | Generic stored file reference for non-image media |
| `TrackStreamMetadata` | — | Metadata for a streamed track |

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `ImagePortInterface` | Image CRUD operations (store, retrieve, delete, list) | Doctrine repository |
| `StoragePortInterface` | Abstract file storage for binary data (read, write, delete, exists) | `FlysystemStorage` (Flysystem adapter) |

## API Endpoints

All endpoints are prefixed with `/api`.

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/images/{publicId}` | Get image metadata (dimensions, MIME type, size) |
| `GET` | `/api/images/{publicId}/file` | Serve the image file binary |
| `GET` | `/api/images/{publicId}/blurhash` | Get the BlurHash placeholder string for progressive image loading |
| `GET` | `/api/stream/track` | Stream a track by public ID after checking library access; supports byte ranges. With `format` (`opus`, `aac` or `mp3`) and an optional `bitrate` in bits per second, streams a cached transcoded rendition while `transcode.enabled` is on (see [Transcode](transcode.md#audio-renditions)) |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `FlysystemStorage` | Abstract filesystem storage via Flysystem. Provides a unified API regardless of whether files are stored on local disk, S3, or another adapter. |
| `BlurHashGenerator` | Generates a compact BlurHash string from an image. Used by the frontend to render a low-fidelity placeholder while the full image loads. |
| `ImageConverter` | Converts images between formats (e.g., PNG to WebP). Used during upload to normalize formats and reduce file sizes. |
| `ImageEntity` | Doctrine ORM entity for the `images` table. |
| `PruneMissingImagesCommand` | Console command (`app:images:prune-missing`) that removes image records whose backing files no longer exist in storage. |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | Uses `Uuid`, `PublicId`, and `CursorPaginatedResponse` for entity identification and API responses, and `SystemSettingsPortInterface` for the transcoding settings |
| Depends on | Transcode | The track stream transcodes audio through `AudioRenditionPortInterface` (the `Transcode Audio Rendition Contract` Deptrac layer) |
| Depended on by | Catalog | Stores cover art images for albums and artists |
| Depended on by | Notification | Stores image attachments for notifications |
