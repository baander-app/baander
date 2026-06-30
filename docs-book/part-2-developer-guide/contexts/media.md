# Media

The Media context handles media file operations: image storage (album art, user uploads), image format conversion, BlurHash generation for placeholder thumbnails, and media file streaming. It provides the storage abstraction that other contexts use when they need to persist or serve binary files.

## Domain Models

### Aggregate Roots

| Model | Key Properties | Purpose |
|-------|---------------|---------|
| `Image` | public ID, original filename, MIME type, dimensions, size | Represents a stored image with metadata (album art, user avatars, uploaded images) |
| `StoredFile` | public ID, filename, MIME type, size | Generic stored file reference for non-image media |

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
| `GET` | `/api/stream/media` | Stream a media file (supports range requests for video/audio seeking) |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `FlysystemStorage` | Abstract filesystem storage via Flysystem. Provides a unified API regardless of whether files are stored on local disk, S3, or another adapter. |
| `BlurHashGenerator` | Generates a compact BlurHash string from an image. Used by the frontend to render a low-fidelity placeholder while the full image loads. |
| `ImageConverter` | Converts images between formats (e.g., PNG to WebP). Used during upload to normalize formats and reduce file sizes. |
| `ImageEntity` | Doctrine ORM entity for the `images` table. |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | Uses `Uuid`, `PublicId`, and `CursorPaginatedResponse` for entity identification and API responses |
| Depended on by | Catalog | Stores cover art images for albums and artists |
| Depended on by | Notification | Stores image attachments for notifications |
