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

Baander's own apps get tokens from three endpoints: password login, passkey login, and refresh. Other clients use the OAuth 2.0 authorization server: the authorization code grant with PKCE, the device authorization grant, and the refresh token grant, all redeemed at `POST /api/oauth/token`. Every token endpoint requires a DPoP proof and returns a DPoP-bound token pair: the first-party endpoints in Baander's `data` envelope, the OAuth token endpoint in the RFC 6749 format. There is no client credentials grant.

### Token Response

A successful login or refresh request returns:

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

The OAuth token endpoint, `POST /api/oauth/token`, answers with the same tokens in the RFC 6749 format (section 5.1), without the envelope:

```json
{
  "access_token": "eyJ...",
  "token_type": "DPoP",
  "expires_in": 3600,
  "refresh_token": "...",
  "scope": "library playlist"
}
```

`scope` lists the scopes the access token carries, separated by spaces. The answer carries `Cache-Control: no-store`, `Pragma: no-cache`, and a `DPoP-Nonce` header.

Token lifetimes are configured in `config/packages/auth.yaml`:
- **Access token**: 3600 seconds (1 hour) — `auth.access_token.ttl`
- **Refresh token**: 2592000 seconds (30 days) — `auth.refresh_token.ttl`
- **Authorization code**: 600 seconds (10 minutes) — `auth.auth_code.ttl`
- **Device code**: 900 seconds (15 minutes) — `auth.device_code.ttl`

### DPoP Proofs

Every token request carries a `DPoP` header with a proof JWT (RFC 9449) signed by the client's key. The proof must include a nonce the server issued:

1. Send the request with a proof that has no nonce. The server answers `400` with `{"error": "use_dpop_nonce", "error_description": "..."}` and a `DPoP-Nonce` header.
2. Repeat the request with a new proof whose `nonce` claim is that value.

Each nonce works once and expires after `auth.dpop.nonce_ttl` seconds (default 300). Successful token responses include a `DPoP-Nonce` header for the client's next proof. At the first-party endpoints, a request without a `DPoP` header gets a `400` `ApiError`, and an invalid proof gets the `use_dpop_nonce` answer. The OAuth token endpoint answers a missing or invalid proof with `400` and `{"error": "invalid_dpop_proof", "error_description": "..."}` (RFC 9449), with a `DPoP-Nonce` header as well.

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

Password login, passkey login, and the token endpoint accept an optional `X-Baander-Client-Fingerprint` header. If it is sent, the access token is bound to that value, and every API request with the token must send the same header or get `401` (`AUTH_INVALID_TOKEN`). Refresh keeps the binding. Tokens issued without the header ignore it. WebSocket connections authenticated with a query token do not check it.

### OAuth 2.0 Authorization Server

Clients other than Baander's own apps discover the endpoints at `GET /.well-known/oauth-authorization-server` (RFC 8414). The document roots every endpoint at the issuer (`APP_URL`, for example `https://baander.app`) and advertises `code` as the only response type, the three grant types, `none` and `client_secret_post` client authentication, `S256` as the only PKCE method, the DPoP signing algorithms, and the scopes from `auth.scopes.user_grants` as `scopes_supported`.

**Authorization code with PKCE.** The metadata's `authorization_endpoint` is the web app's consent page, `APP_URL/oauth/authorize` (configured as `auth.oauth.authorization_page_uri`), not an API route. The client sends the user's browser there:

```
https://baander.app/oauth/authorize?response_type=code&client_id=<client_id>&redirect_uri=http://127.0.0.1:53682/callback&scope=library%20playlist&state=<state>&code_challenge=<challenge>&code_challenge_method=S256
```

The page passes the parameters on to the API with the signed-in user's DPoP-bound access token. Third-party clients never call these two endpoints themselves; like other web app calls, they accept cross-origin requests only from `APP_URL`. First, `GET /api/oauth/authorize` with the same query string checks the request:

```
GET /api/oauth/authorize?response_type=code&client_id=<client_id>&redirect_uri=http://127.0.0.1:53682/callback&scope=library%20playlist&state=<state>&code_challenge=<challenge>&code_challenge_method=S256
Authorization: DPoP <accessToken>
DPoP: <proof>
```

```json
{
  "client_id": "V1StGXR8_Z5jdHi6B-myT",
  "client_name": "Baander Player",
  "client_type": "public",
  "scopes": ["library", "playlist"],
  "redirect_uri": "http://127.0.0.1:53682/callback",
  "consent_required": true
}
```

`scopes` are the scopes an approval grants: the requested scopes Baander allows, or the default scopes when none are left. `client_type` is `public`, `confidential`, `first_party`, or `personal_access`. `consent_required` is false only for clients with the first-party flag: the first-party client and the user's own personal access clients. The page may approve those without asking. Another user's personal access client gets `unauthorized_client`.

Then `POST /api/oauth/authorize`, with the same parameters plus `decision`, records the user's decision. The body may be JSON or form-encoded:

```json
{
  "decision": "approve",
  "response_type": "code",
  "client_id": "V1StGXR8_Z5jdHi6B-myT",
  "redirect_uri": "http://127.0.0.1:53682/callback",
  "scope": "library playlist",
  "state": "<state>",
  "code_challenge": "<challenge>",
  "code_challenge_method": "S256"
}
```

```json
{
  "redirect_uri": "http://127.0.0.1:53682/callback?code=<code>&state=<state>&iss=https%3A%2F%2Fbaander.app"
}
```

On denial (`"decision": "deny"`), `redirect_uri` carries `error=access_denied`, `error_description`, `state`, and `iss` instead of `code`. `iss` (RFC 9207) lets the client detect a mix-up between authorization servers. The page navigates to `redirect_uri`; neither endpoint redirects. Both answer `Cache-Control: no-store`, and an unauthenticated request gets `401` `ApiError`.

PKCE with `S256` is required for every client; `plain` and a missing method are rejected. The redirect URI must match a registered one exactly, except that a loopback URI matches on any port (RFC 8252), and it may be omitted only when the client registered exactly one. Errors from either endpoint are `400` with `{"error": "...", "error_description": "..."}`:

- When the client is unknown or revoked, or the redirect URI is missing or not registered, the body has no `redirect_uri`, and the status is `400` even for `invalid_client`. The page shows the error to the user and must not redirect.
- Any later error, such as `unsupported_response_type`, `unauthorized_client` for a device client, `invalid_request` for a missing or wrong PKCE challenge, or `access_denied` for a disabled account, adds `redirect_uri`: the client's redirect URI carrying `error`, `error_description`, `state`, and `iss`. The page navigates there.

The client then redeems the code:

```
POST /api/oauth/token
Content-Type: application/x-www-form-urlencoded
DPoP: <proof>

grant_type=authorization_code&client_id=<client_id>&code=<code>&redirect_uri=http://127.0.0.1:53682/callback&code_verifier=<verifier>
```

**Device authorization.** A device client posts its `client_id` and an optional `scope`, form-encoded or JSON, to `POST /api/oauth/device/authorize`:

```
POST /api/oauth/device/authorize
Content-Type: application/x-www-form-urlencoded

client_id=<client_id>&scope=library
```

```json
{
  "device_code": "...",
  "user_code": "BCDF-GHJK",
  "verification_uri": "https://baander.app/device",
  "verification_uri_complete": "https://baander.app/device?user_code=BCDF-GHJK",
  "expires_in": 900,
  "interval": 5
}
```

A missing `client_id` (`invalid_request`) or a client that is not a device client (`unauthorized_client`) gets `400`; an unknown or revoked client gets `401` `invalid_client`. The device shows the user code and `verification_uri`, or encodes `verification_uri_complete` in a QR code, and polls the token endpoint with `grant_type=urn:ietf:params:oauth:grant-type:device_code` and `device_code`.

`verification_uri` is the web app's device page (`auth.device.verification_uri`). For the signed-in user, the page looks the code up with `GET /api/oauth/device/verify?user_code=BCDF-GHJK`:

```json
{
  "data": {
    "userCode": "BCDF-GHJK",
    "clientId": "V1StGXR8_Z5jdHi6B-myT",
    "clientName": "Living room TV",
    "scopes": ["library"],
    "expiresAt": "2026-10-07T12:15:00+00:00"
  }
}
```

It then sends the user's decision to `POST /api/oauth/device/approve` with `{"userCode": "BCDF-GHJK", "decision": "approve"}` or `"deny"`, and gets `{"data": {"decision": "approved", "message": "..."}}`, or `"denied"`. Case, spaces, and dashes in the user code do not matter. An unusable code gets a `400` `ApiError` whose `error.details.reason` is `user_code_required` (lookup only), `invalid_user_code`, or `device_already_processed`.

Polls answer `authorization_pending` until the user decides, `slow_down` when they come sooner than the interval (which then grows by 5 seconds), `access_denied` after a denial, and `expired_token` after expiry. An approved code is redeemed once.

**Refresh at the token endpoint.** `grant_type=refresh_token` rotates a pair exactly as `POST /api/auth/refresh` does, and the refresh token must have been issued to the authenticated client.

The token endpoint accepts form-encoded or JSON parameters. Public clients authenticate with `client_id` alone; confidential clients add `client_secret`. Errors use the RFC 6749 shape `{"error": "...", "error_description": "..."}`: `400`, or `401` for `invalid_client`. Every answer, OAuth errors included, carries `Cache-Control: no-store`, `Pragma: no-cache`, and a `DPoP-Nonce` for the next proof. A successful answer uses the RFC 6749 token response above.

**Personal access clients.** `GET` and `POST /api/oauth/clients/` list and create the current user's personal access clients, and `DELETE /api/oauth/clients/{publicId}` revokes one together with its access and refresh tokens. Only the owner can revoke a client. The list carries no client secret.

### OAuth Client Administration

Administrators manage device, public and confidential clients under `/api/admin/oauth/clients`. Listing requires `ROLE_ADMIN`; every change requires `ROLE_SUPER_ADMIN`. Each endpoint has an `app:oauth:client:*` console command that applies the same rules.

`GET /api/admin/oauth/clients` lists every client except personal access clients, revoked ones included:

```json
{
  "data": [
    {
      "clientId": "V1StGXR8_Z5jdHi6B-myT",
      "name": "Living room TV",
      "type": "device",
      "redirectUris": [],
      "revoked": false,
      "createdAt": "2026-10-07T12:00:00+00:00",
      "updatedAt": "2026-10-07T12:00:00+00:00"
    }
  ]
}
```

`type` is `device`, `public`, `confidential`, or `first_party`. The first-party login client is listed but cannot be changed through these endpoints.

`POST /api/admin/oauth/clients` registers a client:

```json
{
  "name": "Baander Player",
  "type": "confidential",
  "redirectUris": ["https://player.baander.app/callback"]
}
```

`name` is at most 100 characters. `type` is `device`, `public`, or `confidential`. `redirectUris` holds 1 to 10 URIs for public and confidential clients and must be omitted or empty for device clients. Each URI must be absolute, without a fragment or credentials, and use `https`, `http` on a loopback host, or a private-use scheme in reverse domain form. The answer is `201` with the client and `clientSecret`:

```json
{
  "data": {
    "clientId": "V1StGXR8_Z5jdHi6B-myT",
    "name": "Baander Player",
    "type": "confidential",
    "redirectUris": ["https://player.baander.app/callback"],
    "revoked": false,
    "createdAt": "2026-10-07T12:00:00+00:00",
    "updatedAt": "2026-10-07T12:00:00+00:00",
    "clientSecret": "<43-character secret>"
  }
}
```

`clientSecret` is the confidential client's secret, shown in this response only; Baander stores its SHA-256 digest. It is `null` for device and public clients. Invalid input gets `422`: a validation error for a missing or malformed field, or `error.details.reason` `invalid_registration` when the registration rules reject the name, type, or redirect URIs.

`POST /api/admin/oauth/clients/{clientId}/rotate-secret` gives a confidential client a new secret and answers `200` in the same shape, with the new `clientSecret`. The old secret stops working at once; issued tokens stay valid. `POST /api/admin/oauth/clients/{clientId}/revoke` revokes the client together with every access and refresh token issued to it and answers `200` with the client, without `clientSecret`. Revoking a revoked client succeeds again.

Both take the `client_id` as `{clientId}`; an unknown one gets `404`. A first-party or personal access client gets `409` with `error.details.reason` `protected_client`. Rotation also answers `409` for a client without a secret (`no_secret`) or a revoked one (`revoked`). Responses that carry a secret also carry `Cache-Control: no-store`.

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
