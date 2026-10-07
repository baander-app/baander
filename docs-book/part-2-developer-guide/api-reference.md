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

Baander issues tokens only to its own clients. Three endpoints issue tokens: password login, passkey login, and refresh. All three require a DPoP proof and return the same token response. There is no authorization code, device, or client credentials flow.

### Token Response

A successful login or refresh returns:

```json
{
  "data": {
    "accessToken": "eyJ...",
    "tokenType": "DPoP",
    "expiresIn": 3600,
    "refreshToken": "..."
  }
}
```

Login responses also include a `user` object inside `data`.

| Field | Description |
|-------|-------------|
| `accessToken` | JWT bound to the client's DPoP key |
| `tokenType` | Always `DPoP` |
| `expiresIn` | Access token lifetime in seconds (default: 3600 / 1 hour) |
| `refreshToken` | Opaque token used to obtain a new token pair without signing in again |

Token lifetimes are configured in `config/packages/auth.yaml`:
- **Access token**: 3600 seconds (1 hour) — `auth.access_token.ttl`
- **Refresh token**: 2592000 seconds (30 days) — `auth.refresh_token.ttl`

### DPoP Proofs

Every token request carries a `DPoP` header with a proof JWT (RFC 9449) signed by the client's key. The proof must include a nonce the server issued:

1. Send the request with a proof that has no nonce. The server answers `400` with `{"error": "use_dpop_nonce", "error_description": "..."}` and a `DPoP-Nonce` header.
2. Repeat the request with a new proof whose `nonce` claim is that value.

Each nonce works once and expires after `auth.dpop.nonce_ttl` seconds (default 300). Successful token responses include a `DPoP-Nonce` header for the client's next proof. A request without a `DPoP` header gets a `400` `ApiError`.

Issued tokens are bound to the proof key: the access token carries `cnf.jkt`. API requests send `Authorization: DPoP <accessToken>` with a `DPoP` proof from the same key that includes the access token hash (`ath`). Signed stream delivery URLs do not need a proof.

### Password Login

```
POST /api/auth/login
Content-Type: application/json
DPoP: <proof>

{"email": "user@baander.app", "password": "secret", "totpCode": "123456"}
```

`totpCode` is required only when the account has TOTP enabled; without it, the response is `401` with code `AUTH_TOTP_REQUIRED`.

### Passkey Login

Passkey login uses the browser's WebAuthn API:

1. `POST /api/auth/passkey/authenticate/options` (optionally with `userId`) returns `{challengeKey, options}`.
2. The client calls `navigator.credentials.get()` with `options`.
3. The client sends the assertion with a `DPoP` header:

```
POST /api/auth/login/passkey
Content-Type: application/json
DPoP: <proof>

{"challengeKey": "...", "response": { ... }, "userId": "..."}
```

`userId` is optional. The server checks the DPoP proof before the assertion, so a nonce challenge leaves the assertion valid for the retry. Disabled accounts cannot sign in.

To register a passkey, an authenticated user calls `POST /api/auth/passkey/options` and passes the browser's credential to `POST /api/auth/passkey/register`.

### Refresh

```
POST /api/auth/refresh
Content-Type: application/json
DPoP: <proof>

{"refreshToken": "..."}
```

The proof must be signed by the key the token pair was issued to. Each refresh token works once; the response contains a new pair with the same key binding. Reusing a consumed refresh token revokes its whole rotation chain.

### Client Fingerprint Binding

Password and passkey login accept an optional `X-Baander-Client-Fingerprint` header. If it is sent, the access token is bound to that value, and every API request with the token must send the same header or get `401` (`AUTH_INVALID_TOKEN`). Refresh keeps the binding. Tokens issued without the header ignore it. WebSocket connections authenticated with a query token do not check it.

### Revocation

`POST /api/auth/logout` revokes the current access token. `POST /api/oauth/revoke` (RFC 7009) requires an authenticated request, revokes an access or refresh token, and always returns `200`. Access tokens can be verified with the keys at `GET /.well-known/jwks.json`.

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
