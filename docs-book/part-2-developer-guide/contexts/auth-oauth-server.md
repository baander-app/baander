# OAuth Authorization Server

The authorization server supports pushed authorization requests with authorization codes, device authorization and refresh. This page describes their handlers and persistence rules. [OAuth Client Flows](../development/api-oauth.md) gives the requests and responses.

## Token Endpoint

`POST /api/oauth/token` is public: the client and the grant identify the caller, not a user token. Parameters may be form-encoded (RFC 6749) or a JSON object. `grant_type` selects the command:

| `grant_type` | Command | Required parameters |
|--------------|---------|---------------------|
| `authorization_code` | `ExchangeAuthorizationCodeCommand` | `code`, `code_verifier`, and `redirect_uri` unless the client registered exactly one |
| `urn:ietf:params:oauth:grant-type:device_code` | `ExchangeDeviceCodeCommand` | `device_code` |
| `refresh_token` | `ExchangeRefreshTokenCommand` | `refresh_token` |

`OAuthClientAuthenticator` authenticates the client by `client_id` and its registered method (see [Client Authentication](auth-client-authentication.md#client-authentication)).

Errors follow RFC 6749: `{"error": "...", "error_description": "..."}`, with `400` except for `invalid_client`, which is `401`. Every controller answer, success or error, carries `Cache-Control: no-store`, `Pragma: no-cache`, and the next `DPoP-Nonce`; a server fault during the grant is `503` with `Retry-After` and no nonce (see [DPoP store outage](auth-tokens.md#dpop-store-outage)). At this endpoint a refresh token must belong to the authenticated client; `ExchangeRefreshTokenHandler` reports every refresh rejection as `invalid_grant` without saying why.

Each grant handler charges `OAuthClientThrottleInterface` right after the client authenticates, so a caller who merely names a client in `client_id` cannot spend its allowance. `RateLimiterOAuthClientThrottle` keys a client with a secret or keys by its public ID; a public client, which has no credential to prove, by its public ID and the caller's network, the `/24` of an IPv4 address or the `/64` of an IPv6 address. A client over its allowance gets `429` with `Retry-After`. Like the per-IP limits, the per-client limits are off in the `dev` environment.

## Authorization Code Grant

Every authorization request is pushed first (RFC 9126), and the authorization endpoint accepts nothing else. A browser redirect cannot carry the user's DPoP-bound access token, so the authorization endpoint is a page of the web app, not an API route. The RFC 8414 metadata advertises `auth.oauth.authorization_page_uri` (by default `APP_URL/oauth/authorize`) as `authorization_endpoint`, `POST /api/oauth/par` as `pushed_authorization_request_endpoint`, and `require_pushed_authorization_requests: true`.

#### Pushed Authorization Requests

`POST /api/oauth/par` (route `oauth_par`) is public, like the token endpoint: it has the same `security.yaml` access rule and the same CORS entry, and it accepts form-encoded or JSON parameters. `OAuthController::par` refuses a `request_uri` parameter with `invalid_request` (RFC 9126 section 2.1) and a `request` parameter with `request_not_supported`, requires `client_id`, and dispatches `PushAuthorizationRequestCommand`. `PushAuthorizationRequestHandler` then does three things:

1. It authenticates the client through `OAuthClientAuthenticator`, exactly as the token endpoint does (see [Client Authentication](auth-client-authentication.md#client-authentication)).
2. It runs `AuthorizationRequestValidator::validatePush()`, every check that needs no user. The `redirect_uri` must match a registered URI exactly; a loopback URI (`http` on `localhost`, `127.0.0.1`, or `[::1]`) also matches on any port, because native apps listen on an ephemeral one (RFC 8252 section 7.3). The parameter may be omitted only when the client registered exactly one URI. `response_type` must be `code`. Device clients are refused (`unauthorized_client`). PKCE is required for every client: `code_challenge` must be a base64url SHA-256 digest and `code_challenge_method` must be `S256`. Requested scopes are filtered against `auth.scopes.user_grants`.
3. It derives the key binding (RFC 9449 section 10.1). `TokenEndpointDpopListener` covers `oauth_par` as well as `oauth_token`. A push without a `DPoP` header passes the listener without a `VerifiedDpopProof`; a push with one gets the full token endpoint check, nonce challenge included. The handler then binds:
   - a verified proof alone: its `jkt`;
   - `dpop_jkt` alone: the parameter, which must be a 43-character base64url SHA-256 thumbprint or the push fails with `invalid_request`;
   - both: they must be equal under `hash_equals()`, or the push fails with `invalid_dpop_proof`;
   - neither: `invalid_request`.

The handler saves a `PushedAuthorizationRequest` and answers `201` with `{request_uri, expires_in}`. Errors answer the client directly in the RFC 6749 shape, `401` for `invalid_client` and `400` otherwise. Every answer carries `Cache-Control: no-store` and `Pragma: no-cache`, and an answer to a verified proof carries the next `DPoP-Nonce`. A server fault while storing the request is `503` with `Retry-After` and no nonce, as at the token endpoint. `OAuthController::par` answers a body larger than 64 KiB with `413` `invalid_request` before parsing it, and the handler refuses a `state` longer than 1024 bytes (`PushedAuthorizationRequest::MAX_STATE_BYTES`) with `invalid_request`; `chk_oauth_pushed_authorization_requests_state_length` holds the same bound in the database. The per-IP limiter `oauth_par_ip` runs before the request is read, and the per-client limiter `oauth_par_client` is charged through `OAuthClientThrottleInterface` once the client has authenticated, as at the token endpoint, so a public client cannot fill the table.

`oauth_pushed_authorization_requests` holds the client (`fk_oauth_pushed_authorization_requests_client_id`, `ON DELETE CASCADE`), the redirect URI, the scopes, `state`, the S256 challenge (`chk_oauth_pushed_authorization_requests_code_challenge_method`), the key thumbprint `dpop_jkt` (`NOT NULL`), `expires_at` and `consumed_at`. It has no user column, because the user exists only at decision time. The request URI is `urn:ietf:params:oauth:request_uri:` followed by a `TokenId`, stored as `request_id` under `uniq_oauth_pushed_authorization_requests_request_id`; a lookup fails as unknown on any other shape. The request lives for `auth.par.request_uri_ttl` seconds (300 by default), which leaves room for a sign-in with TOTP or a passkey before consent.

#### Consent

The client sends the user's browser to the consent page with only `client_id` and `request_uri`. The page passes both to two API calls, each made with the signed-in user's DPoP-bound access token. The calls read only `client_id`, `request_uri` and, for the decision, `decision`:

1. `GET /api/oauth/authorize?client_id=...&request_uri=...` looks the pushed request up without using it. `GetAuthorizationRequestHandler` answers flat JSON `{client_id, client_name, client_type, scopes, redirect_uri, consent_required}`. `scopes` are the scopes an approval grants: the pushed scopes Baander allows. `consent_required` is false only for clients with the first-party flag: the first-party client and the user's own personal access clients. The page may approve those without asking. Baander keeps no record of earlier consent, so every other client needs the user's approval each time.
2. `POST /api/oauth/authorize` with `client_id`, `request_uri` and `decision` (`approve` or `deny`), form-encoded or JSON, records the decision. `CreateAuthorizationCodeHandler` answers `{redirect_uri}`: the pushed redirect URI carrying `code`, the pushed `state`, and `iss` on approval, or `error=access_denied`, `error_description`, `state`, and `iss` on denial. The `iss` parameter (RFC 9207) lets the client detect a mix-up between authorization servers.

Neither call redirects. The page navigates to the returned `redirect_uri` itself, after checking that it keeps the scheme, host, port and path of the `redirect_uri` the lookup returned. Both calls answer with `Cache-Control: no-store` and `Pragma: no-cache`, and an unauthenticated call gets `401` `ApiError`. Like every API route other than the token, pushed authorization request and device authorization endpoints, `/api/oauth/authorize` accepts cross-origin requests only from `APP_URL`.

`AuthorizationRequestValidator` checks both calls in two stages:

1. **The pushed request.** `OAuthController` answers a missing `client_id` or `request_uri` with `invalid_request` before any handler runs. `resolvePushedRequest()` then identifies the client and loads the pushed request; an unknown or revoked client is `invalid_client`. A request URI that is malformed, unknown, pushed by another client, already used or expired is `invalid_request_uri`. These errors must not be sent to the client (RFC 6749 section 4.1.2.1). The answer is `400` with `{error, error_description}` and no `redirect_uri`, even for an unknown client, because `401` would read to the page as an expired session. The page shows the error to the user.
2. **The user.** `validate()` adds the checks that depend on the signed-in user. A personal access client serves only its owner, and anyone else gets `unauthorized_client`; a disabled or missing user gets `access_denied`. These errors answer `400` with `{error, error_description, redirect_uri}`, where `redirect_uri` is the pushed redirect URI carrying `error`, `error_description`, the pushed `state`, and `iss`; the page navigates there. They leave the pushed request unused: a reload repeats the same error redirect, and the request expires within minutes.

A denial runs both stages, as an approval does, so another user cannot use up a request for someone's personal access client by denying it: the request stays unused and the denial is redirected with `unauthorized_client`. Either decision uses the request up through `PushedAuthorizationRequestRepositoryInterface::consume()`, one conditional `UPDATE` that sets `consumed_at` only where the row is unconsumed, unexpired by `clock_timestamp()` and owned by the client. An approval runs it inside `TransactionPortInterface::run()` together with saving the code, so of two concurrent decisions one issues a code and the other gets `invalid_request_uri`.

The code is stored with its redirect URI, `code_challenge`, `code_challenge_method` and the pushed key thumbprint in `dpop_jkt` (`NOT NULL`). The schema defines `chk_oauth_auth_codes_code_challenge_method` (`code_challenge_method = 'S256'`). A code lives for `auth.auth_code.ttl` seconds (60 by default): it is created after consent, so it only has to survive the redirect and the token request.

At the token endpoint, `ExchangeAuthorizationCodeHandler` requires that the code belongs to the authenticated client, is unexpired and unused, was issued for the same redirect URI, and matches the `code_verifier`. After the PKCE check, `AuthCode::isBoundTo()` compares the token request's proof `jkt` with the stored thumbprint under `hash_equals()`, and a proof from another key gets `invalid_grant`. The handler redeems the code atomically in the transaction that stores the token pair, so concurrent redemptions yield one pair. The conditional update that uses the code up also records, in `oauth_auth_codes.chain_id`, the token chain the redemption started. When the client presents a redeemed code again, `TokenChainRevoker` revokes that chain and commits before the handler answers `invalid_grant`; the loser of a concurrent redemption revokes nothing.

## Device Authorization Grant

The device flow (RFC 8628) serves devices without a browser, such as TV apps. Only clients registered as device clients may use it.

1. The device posts `client_id` and a space-separated `scope`, form-encoded or JSON, to `POST /api/oauth/device/authorize`, which is public. The client authenticates through `OAuthClientAuthenticator::authenticate()`, with its credentials read as at the token endpoint. The RFC 8628 answer contains `device_code`, `user_code` (such as `BCDF-GHJK`), `verification_uri`, `verification_uri_complete`, `expires_in`, and `interval`, with `Cache-Control: no-store`. `verification_uri` is `auth.device.verification_uri`, by default the issuer followed by `/device`, the web app's device page; the complete URI adds `?user_code=`. A missing `client_id`, a client that is not a device client or a request with no grantable scope gets `400` (`invalid_request`, `unauthorized_client` or `invalid_scope`); an unknown or revoked client gets `401` `invalid_client`.
2. The device shows the user code and polls the token endpoint with the device code grant, no faster than `interval` seconds.
3. On the device page, the signed-in user's code is looked up with `GET /api/oauth/device/verify?user_code=...`, which answers `{data: {userCode, clientId, clientName, scopes, expiresAt}}`. `scopes` are the scopes an approval grants. The page then sends `POST /api/oauth/device/approve` with `{userCode, decision}`, where `decision` is `approve` or `deny`, and gets `{data: {decision, message}}` with `decision` `approved` or `denied`. Case, spaces, and dashes in the user code do not matter. Both endpoints answer an unusable code with a `400` `ApiError` whose `error.details.reason` is `user_code_required` (lookup only), `invalid_user_code`, or `device_already_processed`. `ApproveDeviceCodeHandler` approves with one conditional `UPDATE` that succeeds only while the code is undecided and unexpired, so of two concurrent approvals the second gets `device_already_processed` and raises no `DeviceCodeApproved`.

While the user has not decided, a poll answers `authorization_pending`. A poll sooner than the current interval answers `slow_down`, and that device code's interval grows by 5 seconds (RFC 8628 section 3.5). A denied request answers `access_denied` and an expired one `expired_token`. An approved code is redeemed once, in the transaction that stores the token pair; a second redemption gets `invalid_grant`.

Device codes live for `auth.device_code.ttl` seconds (900 by default), and the initial interval is `auth.device_code.interval` (5 seconds). Approval raises `DeviceCodeApproved`, which notifies the user as a Security event.

## Purging Expired Codes

`PurgeExpiredOAuthCodesCommand` deletes authorization codes, device codes and pushed authorization requests whose `expires_at` is more than one hour in the past, in any state. The hour keeps an expired device code long enough for a polling device to get `expired_token` instead of `invalid_grant`. Codes with a null `expires_at` never expire in the domain, so the purge keeps them; a pushed request always has an expiry. `PurgeExpiredOAuthCodesHandler` calls `deleteExpiredBefore()` on `AuthCodeRepositoryInterface`, `DeviceCodeRepositoryInterface` and `PushedAuthorizationRequestRepositoryInterface` and returns `PurgedOAuthCodesDTO` with the three counts and the cutoff. There is no index on `expires_at`: each table holds about a day of short-lived rows, so a daily scan costs less than maintaining an index on every insert.

The command implements the Scheduler's `SchedulableCommandInterface`. The initial migration seeds the daily schedule **Purge expired OAuth codes** (`30 3 * * *`, UTC), and `app:oauth:purge-codes` dispatches the same command. Because the purge keeps the tables small, the full unique index `uniq_oauth_device_codes_user_code` stays: a partial index cannot test expiry, since `now()` is not immutable, and lookups by user code rely on one row per code.

## Personal Access Clients

A signed-in user can create named clients for their own tools with `POST /api/oauth/clients/` and list them with `GET /api/oauth/clients/`; the list carries no secret. `Client::createPersonalAccess()` makes a public client owned by the user with the loopback redirect URI `http://localhost`. `DELETE /api/oauth/clients/{publicId}` works only for the owner; another user's client answers `404`. Revoking a client also revokes its access and refresh tokens, through `ClientRevoker`.
