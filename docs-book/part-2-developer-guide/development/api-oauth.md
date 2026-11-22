# OAuth Client Flows

Use these flows for clients other than Baander’s own apps. Read the [shared token and DPoP contract](api-authentication.md#token-response) first, then choose authorization-code, device (RFC 8628) or refresh flow. [Client authentication](api-oauth-client-authentication.md) explains credentials and assertions.

### OAuth 2.0 Authorization Server

Clients other than Baander's own apps discover the endpoints at `GET /.well-known/oauth-authorization-server` (RFC 8414). The document roots every endpoint at the issuer (`APP_URL`, for example `https://baander.app`). It advertises `code` as the only response type, the three grant types, `S256` as the only PKCE method, the DPoP signing algorithms, and the scopes from `auth.scopes.user_grants` as `scopes_supported`. It lists `none`, `client_secret_basic`, `client_secret_post` and `private_key_jwt` as client authentication methods, and `token_endpoint_auth_signing_alg_values_supported` names the algorithms a client assertion may be signed with. It also names `pushed_authorization_request_endpoint` and sets `require_pushed_authorization_requests` to `true`. It names no `revocation_endpoint`: `POST /api/oauth/revoke` authenticates the signed-in user, not a client, and only first-party tokens reach it (see [Revocation](api-authentication.md#revocation)).

A client asks for at least one of the scopes in `scopes_supported` and gets a [delegated token](api-authentication.md#first-party-and-delegated-tokens) limited to them. There is no default scope.

#### Authorization code with PKCE

Every authorization request is pushed first (RFC 9126). The authorization endpoint accepts only the `client_id` and the `request_uri` a push returned, never the request parameters themselves. The flow has four steps:

1. The client posts the request to `POST /api/oauth/par` on the back channel, authenticated as at the token endpoint and bound to its DPoP key.
2. It sends the user's browser to the authorization endpoint with `client_id` and the returned `request_uri`.
3. The consent page looks the pushed request up and records the user's decision.
4. The client redeems the code at the token endpoint with a proof from the bound key.

#### Pushing the request

The parameters may be form-encoded (RFC 9126) or JSON, in a body of at most 64 KiB; a larger body answers `413` with `invalid_request` before it is parsed. A public client sends its `client_id` alone; a confidential client adds its secret or a client assertion, by its registered method (see [Client authentication](api-oauth-client-authentication.md#client-authentication)). The request must bind a DPoP key in one of two ways (RFC 9449 section 10.1):

- a `DPoP` header carrying a proof, checked exactly as at the token endpoint, including the server nonce;
- the `dpop_jkt` parameter, the base64url SHA-256 JWK thumbprint (RFC 7638) of the key, 43 characters long.

A request may carry both, and then they must name the same key. The authorization code issued for the request is bound to that key. A request with a proof:

```
POST /api/oauth/par
Content-Type: application/x-www-form-urlencoded
DPoP: <proof with nonce>

response_type=code&client_id=V1StGXR8_Z5jdHi6B-myT&redirect_uri=http://127.0.0.1:53682/callback&scope=library%20playlist&state=<state>&code_challenge=<challenge>&code_challenge_method=S256
```

A client that sends no proof names its key instead, for example `...&code_challenge_method=S256&dpop_jkt=0ZcOCORZNYy-DWpqq30jZyJGHTN0d2HglBV3uiguA4I`. Either way, a stored request is answered with `201`:

```json
{
  "request_uri": "urn:ietf:params:oauth:request_uri:6esc_11ACC5bwc014ltc14eY22c",
  "expires_in": 300
}
```

The `request_uri` stays usable for `auth.par.request_uri_ttl` seconds (300 by default) and is used up by one decision. A push runs every authorization request check that needs no user. The redirect URI must match a registered one exactly, except that a loopback URI matches on any port (RFC 8252), and it may be omitted only when the client registered exactly one. `response_type` must be `code`, and device clients and Baander's own first-party client are refused (`unauthorized_client`). PKCE with `S256` is required for every client; `plain` and a missing method are rejected. `state` may be at most 1024 bytes. Requested scopes outside `auth.scopes.user_grants` are dropped, and a request left with none of them, including one that names no scope, gets `invalid_scope`.

Errors at the push answer the client directly, in the RFC 6749 shape `{"error": "...", "error_description": "..."}`: `401` for `invalid_client`, otherwise `400`. They include:

- `invalid_request` when the request has neither a proof nor `dpop_jkt`, when `dpop_jkt` is malformed, when `state` is longer than 1024 bytes, or when it carries a `request_uri` parameter;
- `invalid_scope` when none of the requested scopes can be granted;
- `invalid_dpop_proof` when the proof and `dpop_jkt` name different keys;
- `request_not_supported` for a `request` parameter, since request objects are not supported.

Every answer carries `Cache-Control: no-store`, and an answer to a request with a verified proof carries the next `DPoP-Nonce`. A rate-limited push gets `429`. A push the server cannot store gets `503` with `Retry-After` and no nonce; this covers a database restart, an unavailable rate limit or nonce store, and a `jwks_uri` key set that cannot be fetched. Push again after the delay.

#### Sending the user to the consent page

The metadata's `authorization_endpoint` is the web app's consent page, `APP_URL/oauth/authorize` (configured as `auth.oauth.authorization_page_uri`), not an API route. The client sends the browser there with only two parameters:

```
https://baander.app/oauth/authorize?client_id=V1StGXR8_Z5jdHi6B-myT&request_uri=urn%3Aietf%3Aparams%3Aoauth%3Arequest_uri%3A6esc_11ACC5bwc014ltc14eY22c
```

The page passes them on to two API endpoints, `GET` and `POST /api/oauth/authorize`, with the signed-in user's DPoP-bound access token. Third-party clients never call these endpoints themselves; like other web app calls, they accept cross-origin requests only from `APP_URL`. First, `GET /api/oauth/authorize` looks the pushed request up without using it:

```
GET /api/oauth/authorize?client_id=V1StGXR8_Z5jdHi6B-myT&request_uri=urn%3Aietf%3Aparams%3Aoauth%3Arequest_uri%3A6esc_11ACC5bwc014ltc14eY22c
Authorization: DPoP <accessToken>
DPoP: <proof>
```

```json
{
  "client_id": "V1StGXR8_Z5jdHi6B-myT",
  "client_name": "Baander Player",
  "client_type": "public",
  "scopes": ["library", "playlist"],
  "redirect_uri": "http://127.0.0.1:53682/callback"
}
```

`scopes` are the scopes an approval grants: the pushed scopes Baander allows. The consent page describes each to the user: "See your name and profile" (`profile`), "See your email address" (`email`), "Browse and play your library, and control playback on your devices" (`library`), and "See, create and change your playlists" (`playlist`). `client_type` is `public`, `confidential`, or `personal_access`; the first-party client makes no authorization requests, and its `client_id` gets `invalid_client`. The page asks the user about every request and approves none on its own. For a personal access client it names the client type and the loopback redirect with its port. A personal access client's tokens are delegated and limited to their scopes. Another user's personal access client gets `unauthorized_client`.

Then `POST /api/oauth/authorize` records the user's decision on the same pushed request. The body may be JSON or form-encoded:

```json
{
  "decision": "approve",
  "client_id": "V1StGXR8_Z5jdHi6B-myT",
  "request_uri": "urn:ietf:params:oauth:request_uri:6esc_11ACC5bwc014ltc14eY22c"
}
```

```json
{
  "redirect_uri": "http://127.0.0.1:53682/callback?code=<code>&state=<state>&iss=https%3A%2F%2Fbaander.app"
}
```

Either decision uses the request up, so it is decided once; of two concurrent decisions, one wins and the other gets `invalid_request_uri`. A denial runs the same user checks as an approval, so another user cannot use up a request for someone's personal access client by denying it. On denial (`"decision": "deny"`), `redirect_uri` carries `error=access_denied`, `error_description`, the pushed `state`, and `iss` instead of `code`. `iss` (RFC 9207) lets the client detect a mix-up between authorization servers. The page navigates to `redirect_uri`; neither endpoint redirects. Their success and OAuth error answers carry `Cache-Control: no-store`; an unauthenticated request gets a `401` `ApiError` without it.

The two endpoints read only `client_id`, `request_uri` and `decision`. Errors from either endpoint are `400` with `{"error": "...", "error_description": "..."}`:

- When `client_id` or `request_uri` is missing (`invalid_request`), the client is unknown or revoked (`invalid_client`, still `400`), or the pushed request is unknown, malformed, expired, already used or pushed by another client (`invalid_request_uri`), the body has no `redirect_uri`. The same holds for an `invalid_request` from `POST /api/oauth/authorize` whose `decision` is missing or invalid, or whose body is neither JSON nor form-encoded. The page shows the error to the user and must not redirect.
- An error that depends on the user, `unauthorized_client` for another user's personal access client or `access_denied` for a disabled account, adds `redirect_uri`: the pushed redirect URI carrying `error`, `error_description`, the pushed `state`, and `iss`. The page navigates there. These errors leave the pushed request unused.

#### Redeeming the code

An authorization code lives for `auth.auth_code.ttl` seconds (60 by default), long enough for the redirect and the token request. The client redeems it with a proof signed by the key the pushed request was bound to:

```
POST /api/oauth/token
Content-Type: application/x-www-form-urlencoded
DPoP: <proof with nonce, from the bound key>

grant_type=authorization_code&client_id=V1StGXR8_Z5jdHi6B-myT&code=<code>&redirect_uri=http://127.0.0.1:53682/callback&code_verifier=<verifier>
```

A proof from any other key gets `invalid_grant`, as do a wrong `code_verifier` and a code that is expired, used, or another client's. A code presented again after it was redeemed is treated as stolen: Baander revokes the token chain its redemption started, and commits the revocation, before answering `invalid_grant`. Of two concurrent redemptions, the loser does not revoke the winner's chain.

#### Device authorization

A device client posts its `client_id` and a `scope`, form-encoded or JSON, to `POST /api/oauth/device/authorize`. The client authenticates as at the token endpoint:

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

A missing `client_id` (`invalid_request`), a client that is not a device client (`unauthorized_client`) or a request with no scope Baander grants (`invalid_scope`) gets `400`; an unknown or revoked client gets `401` `invalid_client`. The device shows the user code and `verification_uri`, or encodes `verification_uri_complete` in a QR code, and polls the token endpoint with `grant_type=urn:ietf:params:oauth:grant-type:device_code` and `device_code`.

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

It then sends the user's decision to `POST /api/oauth/device/approve` with `{"userCode": "BCDF-GHJK", "decision": "approve"}` or `"deny"`, and gets `{"data": {"decision": "approved", "message": "..."}}`, or `"denied"`. Case, spaces, and dashes in the user code do not matter. An unusable code gets a `400` `ApiError` whose `error.details.reason` is `user_code_required` (lookup only), `invalid_user_code`, or `device_already_processed`. A code is decided once: of two concurrent approvals, the second gets `device_already_processed`.

Polls answer `authorization_pending` until the user decides, `slow_down` when they come sooner than the interval (which then grows by 5 seconds), `access_denied` after a denial, and `expired_token` after expiry. An approved code is redeemed once.

#### Refresh at the token endpoint

`grant_type=refresh_token` rotates a pair exactly as `POST /api/auth/refresh` does, and the refresh token must have been issued to the authenticated client. This is the only refresh path for delegated tokens. Baander's own first-party client gets `unauthorized_client` here, as with every grant at this endpoint; its apps refresh at `POST /api/auth/refresh`.

The token endpoint accepts form-encoded or JSON parameters, and every grant authenticates the client as described in [Client Authentication](api-oauth-client-authentication.md#client-authentication). Errors use the RFC 6749 shape `{"error": "...", "error_description": "..."}`: `400`, or `401` for `invalid_client`. Besides the per-IP limit, each client has its own request allowance, charged only after it has authenticated, so a caller who merely names a client cannot spend that client's allowance. A client with a secret or keys has one allowance for all addresses; a public client has one per caller network (the `/24` of an IPv4 address, the `/64` of an IPv6 address).

The same holds at the pushed authorization request endpoint. Every token answer and OAuth error carries `Cache-Control: no-store`, `Pragma: no-cache`, and a `DPoP-Nonce` for the next proof. Two answers carry no nonce: a rate-limited request gets `429`, and a request the server cannot decide gets `503` with `Retry-After` and `Cache-Control: no-store`. That happens, for example, while its database restarts, while the nonce store or the assertion `jti` store is unavailable, or when a client's `jwks_uri` set cannot be fetched; keep the tokens and retry after the delay.

A successful answer uses the [RFC 6749 token response](api-authentication.md#token-response).

#### Personal access clients

`GET` and `POST /api/oauth/clients/` list and create the current user's personal access clients, and `DELETE /api/oauth/clients/{publicId}` revokes one together with its access and refresh tokens. Only the owner can revoke a client. The list carries no client secret.
