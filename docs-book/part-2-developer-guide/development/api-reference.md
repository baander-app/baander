# API Reference

Bånder exposes a resource-oriented JSON API documented with OpenAPI. It identifies resources by 21-character NanoID public IDs, pages collections by cursor or offset, reports errors in a standard envelope, and builds responses in resource classes owned by each context.

## OpenAPI Specification

`NativeOpenApiExporter` assembles native `OpenApi\Spec` declarations from
controllers and referenced request/resource classes, resolves their schemas and
compiles the document through the native OpenAPI 3.0 pipeline. Symfony route
metadata supplies route paths, methods and missing path parameters. Explicit
request schemas own required fields, defaults, nullability and constraints;
Symfony validation does not infer them for the exporter.

Reusable schemas use stable `component:` names and keyed Schema properties.
Native class references connect request bodies and responses to those schemas.
See [Adding a Feature](adding-a-feature.md#10-request-dto) for declaration examples.

The CLI command and protected `GET /api/doc.json` endpoint share the application
`OpenApiExporterInterface`; its service alias selects the native Infrastructure
implementation. The JSON route is declared in `config/routes/openapi.yaml`.
The repository tracks `openapi.json`, which supplies the web Orval client. After
changing an API contract, export the specification and regenerate the client:

```bash
make exec cmd="php bin/console app:export-openapi-spec --output=/tmp/baander-openapi.json"
make exec cmd="cat /tmp/baander-openapi.json" > openapi.json
```

The preview mounts application source read-only, so the first command writes the
export to the container's temporary directory and the second redirects it to the
host's tracked file.

Run `app:export-openapi-spec --check` to compare the tracked file with the current export without writing it. The web client has `yarn generate` and `yarn generate:check` scripts in `ui/web`; the latter regenerates the client and fails on a tracked diff.

The default export covers application `/api` routes; the generated documentation endpoint `/api/doc.json` is excluded. Operational endpoints such as `/health` and `/metrics`, WebSocket paths and `/.well-known` discovery endpoints are outside that export.

See the [export command documentation](../../part-1-operator-guide/commands/app-export-openapi-spec.md) for full details.

## REST Conventions

### Resource-Based URLs

Endpoints follow a resource-oriented pattern. A collection lives under a plural noun; each endpoint defines the identifier of an individual resource.

| Pattern | Example |
|---------|---------|
| List collection | `GET /api/playlists/` |
| Create resource | `POST /api/playlists/` |
| Read single resource | `GET /api/playlists/{publicId}` |
| Update resource | `PATCH /api/playlists/{publicId}` |
| Delete resource | `DELETE /api/playlists/{publicId}` |

### Public Identities

A persisted entity has an internal UUID and a separate `PublicId`. Public IDs are 21-character NanoIDs matching `^[0-9a-zA-Z_-]{21}$`, for example `V1StGXR8_Z5jdHi6B-myT`. Addressable resources expose their own identity as `publicId`, without the internal primary-key fields `uuid` or `id`. Existing snake-case resource contracts retain `public_id`.

Public requests, responses, cursors, signed delivery paths and WebSocket messages use public IDs for persisted entities. Relationship names such as `userId`, `songId`, `videoId`, `libraryId` and `parentId` retain their names and carry the related entity's public ID. A route parameter named `{id}` can also require a public ID; its name does not authorize a UUID fallback. Deliberate slug routes, such as genre routes, retain their documented public names. Public IDs identify resources; access still depends on ownership, roles and library membership.

Protocol identifiers have separate meanings. OAuth token IDs, WebAuthn credential IDs, provider IDs, browser device tokens and failed-transport integer message IDs are not entity primary keys. Read the parameter schema for these values. WebAuthn user handles use the user's public ID; credential IDs remain authenticator-generated protocol values.

### Device and Playback Identities

A device resource exposes both `publicId`, its persisted public identity, and `deviceId`, the browser-generated UUID token used to register that device. Rename and forget requests at `/api/devices/{deviceId}` take the resource's public ID despite the parameter name. Device registration takes the browser token as `deviceId`.

Playback coordination routes under `/api/session` (`POST /api/session/endpoints`, `/transfer`, `/ack`, `/observe`, `/control`, `/reconcile`, `/recover-local`, `/close`, `/override`, and `GET /api/session/snapshot`) identify the playback endpoint capability through the `X-Baander-Playback-Endpoint` header. `PlaybackSnapshotResource` exposes `selectedDestinationId` and `activeDestinationId` (public IDs of playback destinations, each a browser tab registered as an endpoint), `activeIncarnationId` (the incarnation UUID the server issues when the endpoint registers), and `destinations[]` (`destinationId` + `incarnationId`). A client recognizes its own endpoint by comparing `activeIncarnationId` with the incarnation ID its registration returned.

An EQ profile's `publicId` identifies the profile for updates, deletion and activation. Its optional `deviceId` is an audio-output token supplied by the client; it does not refer to a persisted device. `activeProfileId` carries the selected profile's public ID.

Listening sessions, party sessions and transcode sessions expose their own public IDs. Party REST routes and WebSocket `sessionId` and participant user references use public IDs. Video parameters in `/api/transcode/{videoId}/master.m3u8` and `/api/transcode/{videoId}/manifest.mpd` take the video's public ID, including signed URLs. Segment and rendition URLs retain their transcode-job public IDs. A session queue contains song public IDs.

### Pagination

Each paginated endpoint uses one of three strategies.

**Cursor pagination** is used for large ordered collections, such as the song list (`GET /api/songs/`). Pass `cursor` and `limit` as query parameters. A `CursorPaginatedResponse` includes cursors for both directions.

| Parameter | Type | Description |
|-----------|------|-------------|
| `cursor` | `string`, optional | Opaque cursor from a previous response. Omit to fetch the first page. |
| `limit` | `int`, optional | Items per page. Defaults vary by endpoint. |

**Page-number pagination** is used for the searchable catalog lists (albums, artists, movies, directors, people, TV), favorites and the failed-message list. Pass `page` and `limit` as query parameters. `QueryParameters::page()` reads both and computes the offset.

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | `int`, optional | Page number, starting from 1. |
| `limit` | `int`, optional | Items per page. Defaults vary by endpoint. |

A `page` below 1, a `page` that is not an integer, a `page` for which page × limit exceeds 2⁶³ − 1, the largest signed 64-bit integer, and a `limit` outside the range the OpenAPI document states answer `400`. A page past the last one returns an empty `data` array.

**Limit and offset** is used for lists a client reads to the end, such as the playlist list (`GET /api/playlists/`) and the radio station list (`GET /api/radio/stations`, up to 500 stations a page), and for the activity history and the admin user and login-block lists. Pass `limit` and `offset`, the number of rows to skip, as query parameters; `QueryParameters::pagination()` reads them and answers `400` for a negative offset or a `limit` outside the endpoint's range. Each of these lists except the activity history answers with a `meta` object that holds `total`, `limit` and `offset`. A client that needs the whole list requests the next offset until it has read `total` rows.

## Response Types

### Single Resource

The envelope depends on the endpoint. `ApiResponsesTrait::successResponse()` wraps its payload in `data`; `created()` returns the payload directly with status `201`. For example, playlist updates return this excerpt inside `data`, while playlist and transcode-session creation return their resources directly:

```json
{
  "data": {
    "publicId": "V1StGXR8_Z5jdHi6B-myT",
    "name": "Chill Vibes",
    "createdAt": "2025-06-15T12:00:00+00:00"
  }
}
```

### Cursor-Paginated Collection

The song list returns a `CursorPaginatedResponse`; other cursor-paginated endpoints, such as the notification list and the job monitor, use their own envelopes. The `meta` object contains navigation cursors and page metadata. The cursor below is a placeholder; clients pass the server-provided cursor unchanged and treat its contents as opaque:

```json
{
  "data": [
    { "publicId": "V1StGXR8_Z5jdHi6B-myT", "name": "Chill Vibes" },
    { "publicId": "0123456789abcdefghijk", "name": "Workout Mix" }
  ],
  "meta": {
    "next_cursor": "<opaque cursor from the server>",
    "prev_cursor": null,
    "has_next_page": true,
    "has_previous_page": false,
    "total": 42,
    "per_page": 20,
    "stale_cursor": false
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `next_cursor` | `string \| null` | Cursor to fetch the next page. `null` when there are no more results. |
| `prev_cursor` | `string \| null` | Cursor to fetch the previous page. `null` on the first page. |
| `has_next_page` | `bool` | Whether a next page exists. |
| `has_previous_page` | `bool` | Whether a previous page exists. |
| `total` | `int` | Total number of items matching the query. |
| `per_page` | `int` | Number of items returned per page. |
| `stale_cursor` | `bool` | `true` if a cursor was supplied and the page is empty, for example because the items after it were deleted. The page is not recalculated. A cursor issued for another sort or order gets `400` instead. |

### Offset-Paginated Collection

Returned as a `PaginatedResponse`. The `meta` object contains the page metadata:

```json
{
  "data": [
    { "publicId": "V1StGXR8_Z5jdHi6B-myT", "name": "Chill Vibes" },
    { "publicId": "0123456789abcdefghijk", "name": "Workout Mix" }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 42
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `current_page` | `int` | The current page number (1-indexed). |
| `last_page` | `int` | The last available page number. |
| `per_page` | `int` | Number of items returned per page. |
| `total` | `int` | Total number of items matching the query. |

### Error Responses

Every client error on an `/api` path answers in the `ApiError` format, the framework's own included: an unmatched path or one that fails a route requirement, a method the route does not allow (with its `Allow` header), a body that is not JSON, and every rate limiter's `429` (with `Retry-After`). `ExceptionSubscriber` gives such an HTTP exception the message for its status from the `messages` catalog, in the request's language, and never shows the exception's own text. A path counts as an API path when it starts with `/api/` after percent-decoding, as `ApiRequestPath` reads it, so `/%61pi/...` is answered like `/api/...`. A `401` or `403` takes the envelope on every path; other client errors outside `/api`, such as the web app's pages, keep Symfony's renderer. The OAuth protocol endpoints and DPoP nonce challenges answer with OAuth-style `error` and `error_description` strings instead (RFC 6749, RFC 9449), as described under [Authentication](api-authentication.md#authentication):

```json
{
  "error": {
    "message": "Validation failed.",
    "code": 422
  }
}
```

Validation errors include a `details` key mapping each field path to its list of violation messages:

```json
{
  "error": {
    "message": "Validation failed.",
    "code": 422,
    "details": {
      "name": ["This value should not be blank."],
      "email": ["This is not a valid email address."]
    }
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `message` | `string` | Human-readable error description. |
| `code` | `int \| string` | HTTP status code on standard `ApiError` responses, or a string error code (`AUTH_INVALID_CREDENTIALS`, `AUTH_TOTP_REQUIRED`, `AUTH_INVALID_TOKEN`) on authenticator failures. |
| `details` | `object`, optional | Field-level error details for validation failures or structured error reasons. |


## Resource Pattern

Controllers never serialize domain models directly. Each context defines `AbstractResource` subclasses that map domain models to API response shapes. A resource class exposes a static `from()` method that accepts a domain model or read view and returns the serialized array. This excerpt from `PlaylistResource` shows the public identifier and timestamp field names:

```php
final class PlaylistResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof PlaylistReadView);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            // Other playlist fields omitted from this excerpt.
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

Serialization therefore lives in the context that owns the domain model, and a change to the domain structure does not change the API response shape unless the resource changes. See [Coding Conventions](coding-conventions.md) for the port pattern that controllers use to invoke application logic.
