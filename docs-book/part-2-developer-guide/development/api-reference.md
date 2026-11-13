# API Reference

Bånder exposes a resource-oriented JSON API documented via OpenAPI, using 21-character NanoID public identities, cursor and offset pagination, standardized error envelopes, and context-owned resource classes.

## OpenAPI Specification

Baander generates its OpenAPI specification from source code using Nelmio ApiDoc. The spec is derived from attributes on controllers, request DTOs, and response resources. Route and schema tests check its coverage; export and client-generation checks detect drift in the checked-in artifacts.

The JSON documentation route is `/api/doc.json`; the Swagger UI route at `/api/doc` is currently commented out in `config/routes/nelmio_api_doc.yaml`. The repository tracks `openapi.json`, which supplies the web Orval client. After changing an API contract, export the specification and regenerate the client:

```bash
make exec cmd="php bin/console app:export-openapi-spec --output=/tmp/baander-openapi.json"
make exec cmd="cat /tmp/baander-openapi.json" > openapi.json
```

Run `app:export-openapi-spec --check` to compare the tracked file with the current export without writing it. The web client has `yarn generate` and `yarn generate:check` scripts in `ui/web`; the latter regenerates the client and fails on a tracked diff.

The default export covers application `/api` routes; the generated documentation endpoint `/api/doc.json` is excluded. Operational endpoints such as `/health` and `/metrics`, WebSocket paths and `/.well-known` discovery endpoints are outside that export.

See the [export command documentation](../../part-1-operator-guide/commands/app-export-openapi-spec.md) for full details.

The preview mounts application source read-only. The export commands above write
to its temporary directory, then redirect the result to the host's tracked file.

## REST Conventions

### Resource-Based URLs

Endpoints follow a resource-oriented pattern. Collections live under a plural noun; the identifier for an individual resource is defined by that endpoint.

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

Playback coordination routes under `/api/session` (`POST /api/session/endpoints`, `/transfer`, `/ack`, `/observe`, `/control`, `/reconcile`, `/recover-local`, `/close`, `/override`, and `GET /api/session/snapshot`) identify the playback endpoint capability through the `X-Baander-Playback-Endpoint` header. `PlaybackSnapshotResource` exposes `selectedDestinationId` and `activeDestinationId` (persisted device public IDs), `activeIncarnationId` (the client-generated incarnation UUID), and `destinations[]` (`destinationId` + `incarnationId`), so clients resolve their own browser token through the device list before comparing destination ownership.

An EQ profile's `publicId` identifies the profile for updates, deletion and activation. Its optional `deviceId` is an audio-output token supplied by the client; it does not refer to a persisted device. `activeProfileId` carries the selected profile's public ID.

Listening sessions, party sessions and transcode sessions expose their own public IDs. Party REST routes and WebSocket `sessionId` and participant user references use public IDs. Video parameters in `/api/transcode/{videoId}/master.m3u8` and `/api/transcode/{videoId}/manifest.mpd` take the video's public ID, including signed URLs. Segment and rendition URLs retain their transcode-job public IDs. A session queue contains song public IDs.

### Pagination

Two pagination strategies are available depending on the endpoint.

**Cursor pagination** is used for searchable collections and large datasets. Pass `cursor` and `limit` as query parameters. The response includes cursors for both directions.

| Parameter | Type | Description |
|-----------|------|-------------|
| `cursor` | `string`, optional | Opaque cursor from a previous response. Omit to fetch the first page. |
| `limit` | `int`, optional | Items per page. Defaults vary by endpoint. |

**Offset pagination** is used for simpler collections. Pass `page` and `limit` as query parameters.

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | `int`, optional | Page number, starting from 1. |
| `limit` | `int`, optional | Items per page. Defaults vary by endpoint. |

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

Returned as a `CursorPaginatedResponse`. The `meta` object contains navigation cursors and page metadata. The cursor below is an illustrative placeholder; clients pass the server-provided cursor unchanged and treat its contents as opaque:

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
| `stale_cursor` | `bool` | `true` if the supplied cursor no longer matches the dataset (e.g., items were deleted). Results are recalculated from the nearest valid position. |

### Offset-Paginated Collection

Returned as a `PaginatedResponse`. The `meta` object contains standard page metadata:

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

Application errors generally follow the `ApiError` format. Protocol endpoints and DPoP nonce challenges can instead use OAuth-style `error` and `error_description` strings, as described under [Authentication](api-authentication.md#authentication):

```json
{
  "error": {
    "message": "Validation failed",
    "code": 422
  }
}
```

Validation errors include a `details` key mapping each field path to its list of violation messages:

```json
{
  "error": {
    "message": "Validation failed",
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

Controllers never serialize domain models directly. Instead, each context defines `AbstractResource` subclasses that transform domain models into API-safe response shapes. These resource classes expose a static `from()` method that accepts a domain model or read view and returns the serialized array. This excerpt from `PlaylistResource` shows the public identifier and timestamp field names:

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

This keeps serialization logic co-located with the context that owns the domain model, and ensures the API response shape is decoupled from the internal domain structure. See [Coding Conventions](coding-conventions.md) for details on the port pattern that controllers use to invoke application logic.
