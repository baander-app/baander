# API Reference

## OpenAPI Specification

Baander generates its OpenAPI specification from source code using Nelmio ApiDoc. The spec is derived from attributes on controllers, request DTOs, and response resources, so it always reflects the current codebase.

The interactive Swagger UI is available at `/api/doc` in development environments. There is no static copy of the spec committed to the repository -- it would go stale. Instead, export a fresh copy on demand:

```bash
make exec cmd="php bin/console app:export-openapi-spec"
```

See the [export command documentation](../part-1-operator-guide/commands/app-export-openapi-spec.md) for full details.

## REST Conventions

### Resource-Based URLs

Endpoints follow a resource-oriented pattern. Collections live under a plural noun; individual resources are addressed by their public ID.

| Pattern | Example |
|---------|---------|
| List collection | `GET /api/playlists` |
| Create resource | `POST /api/playlists` |
| Read single resource | `GET /api/playlists/{publicId}` |
| Update resource | `PATCH /api/playlists/{publicId}` |
| Delete resource | `DELETE /api/playlists/{publicId}` |

The `{publicId}` path parameter is a public-facing identifier, separate from the internal UUID v7 primary key. This prevents information leakage while keeping URLs short and opaque.

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

A single resource is wrapped in a top-level `data` object:

```json
{
  "data": {
    "id": "p_abc123",
    "name": "Chill Vibes",
    "created_at": "2025-06-15T12:00:00+00:00"
  }
}
```

### Cursor-Paginated Collection

Returned as a `CursorPaginatedResponse`. The `meta` object contains navigation cursors and page metadata:

```json
{
  "data": [
    { "id": "p_abc123", "name": "Chill Vibes" },
    { "id": "p_def456", "name": "Workout Mix" }
  ],
  "meta": {
    "next_cursor": "eyJpZCI6InBfZGVmNDU2In0",
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
    { "id": "p_abc123", "name": "Chill Vibes" },
    { "id": "p_def456", "name": "Workout Mix" }
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

All errors follow the `ApiError` format:

```json
{
  "error": {
    "message": "Validation failed",
    "code": 422
  }
}
```

Validation errors include a `details` key with field-level messages:

```json
{
  "error": {
    "message": "Validation failed",
    "code": 422,
    "details": {
      "name": "This value should not be blank.",
      "email": "This is not a valid email address."
    }
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `message` | `string` | Human-readable error description. |
| `code` | `int` | HTTP status code. |
| `details` | `object`, optional | Field-level error details for validation failures. |

## Authentication

Baander supports three authentication flows, all backed by OAuth 2.0. All flows return the same token response format.

### Token Response

Regardless of which flow is used, a successful authentication returns:

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ...",
  "refresh_token": "def50200..."
}
```

| Field | Description |
|-------|-------------|
| `token_type` | Always `Bearer` |
| `expires_in` | Access token lifetime in seconds (default: 3600 / 1 hour) |
| `access_token` | JWT used in the `Authorization: Bearer` header for API requests |
| `refresh_token` | Opaque token used to obtain a new access token without re-authenticating |

Token lifetimes are configured in `config/packages/auth.yaml`:
- **Access token**: 3600 seconds (1 hour) — `auth.access_token.ttl`
- **Refresh token**: 2592000 seconds (30 days) — `auth.refresh_token.ttl`
- **Auth code**: 600 seconds (10 minutes) — `auth.auth_code.ttl`

To refresh an expired access token, send a refresh grant to the same token endpoint:

```
POST /api/auth/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token&refresh_token=def50200...&client_id=<client_id>
```

### Password Grant

The simplest flow for first-party clients. Send a `POST` request to `/api/auth/token` with form-encoded parameters:

```
POST /api/auth/token
Content-Type: application/x-www-form-urlencoded

grant_type=password&client_id=<client_id>&username=user@example.com&password=secret
```

### Passkey (WebAuthn)

Passkey authentication uses the browser's built-in WebAuthn API. The flow is browser-mediated:

1. **Registration**: The frontend calls `/api/auth/passkey/register/options` to retrieve a challenge, then passes the browser's credential to `/api/auth/passkey/register`.
2. **Authentication**: The frontend calls `/api/auth/passkey/authenticate/options` to retrieve a challenge, then passes the browser's assertion to `/api/auth/passkey/authenticate`.

The server issues OAuth tokens upon successful authentication, just like the password grant.

### PKCE (Authorization Code Flow)

For third-party or public clients where a client secret cannot be stored securely. This implements OAuth 2.0 with PKCE (Proof Key for Code Exchange):

1. The client generates a `code_verifier` and derives a `code_challenge` from it.
2. The user is redirected to the authorization endpoint with the `code_challenge`.
3. After the user approves, the client receives an authorization code.
4. The client exchanges the code (along with the `code_verifier`) for tokens at the token endpoint.

This is the recommended flow for mobile apps, SPAs, and any client running on an untrusted environment.

## Resource Pattern

Controllers never serialize domain models directly. Instead, each context defines `AbstractResource` subclasses that transform domain models into API-safe response shapes. These resource classes expose a static `from()` method that accepts a domain model and returns the serialized array:

```php
final class PlaylistResource extends AbstractResource
{
    public static function from(Playlist $playlist): array
    {
        return [
            'id' => $playlist->getPublicId()->toString(),
            'name' => $playlist->getName(),
            'created_at' => $playlist->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

This keeps serialization logic co-located with the context that owns the domain model, and ensures the API response shape is decoupled from the internal domain structure. See [Coding Conventions](coding-conventions.md) for details on the port pattern that controllers use to invoke application logic.
