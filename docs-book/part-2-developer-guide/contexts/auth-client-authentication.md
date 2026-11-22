# OAuth Client Authentication and Registration

This reference explains how Auth authenticates registered clients, retrieves their keys and manages their credentials. [OAuth Client Authentication](../development/api-oauth-client-authentication.md) gives request examples.

## Client Authentication

The pushed authorization request endpoint, device authorization and all three token grants pass `ClientCredentials` to `OAuthClientAuthenticator::authenticate()`. The value object carries the required `client_id`, an optional `client_secret` from the body, an optional secret from an HTTP Basic `Authorization` header (`client_secret_basic`), and an optional `client_assertion` with its `client_assertion_type`; an empty body value counts as not sent, and a Basic header counts as sent even with an empty password. `OAuthController` reads Basic credentials as RFC 6749 section 2.3.1 defines them, each part form-urlencoded: the header names the client, a body `client_id` may only repeat it, and a malformed header is `invalid_client`. At the token and pushed authorization request endpoints, an `invalid_client` answer to a request with Basic credentials carries `WWW-Authenticate: Basic` (RFC 6749 section 5.2). The authenticator resolves the client by `client_id` and verifies it by the one method the client was registered with, `Client::getAuthMethod()`:

| `ClientAuthMethod` | Accepts |
|--------------------|---------|
| `none` | Neither a secret nor an assertion |
| `client_secret_post` | A secret in the body or in HTTP Basic, never both, compared in constant time; no assertion |
| `private_key_jwt` | A JWT bearer assertion (`urn:ietf:params:oauth:client-assertion-type:jwt-bearer`) that `ClientAssertionVerifierInterface` accepts; no secret |

Any other combination, including a second method alongside the registered one, is `401` `invalid_client` (RFC 6749 section 2.3). Unknown, malformed, and revoked clients fail the same way, so the answer does not reveal which it was. `identify()` resolves a client without authenticating it, for the consent page's front-channel calls. Both refuse Baander's first-party client, whose apps sign in through password and passkey login: `authenticate()` answers `unauthorized_client` once the client's ID has authenticated it, and `identify()` answers `invalid_client`, an error the consent page shows without redirecting.

`ClientAssertionVerifier` (Infrastructure, on `lcobucci/jwt`) implements the verifier port under the rules of RFC 7523 as updated by RFC 10070:

- `alg` is one of the asymmetric algorithms DPoP proofs use (`JwkPublicKeyConverter::SIGNING_ALGORITHMS`), which the metadata advertises as `token_endpoint_auth_signing_alg_values_supported`; a unit test keeps the two lists equal. `none` and HMAC are refused. `PS256` is verified through web-token's RSA-PSS implementation against the registered JWK, because `lcobucci/jwt` has no PSS signer; RSA keys need at least 2048 bits, and an `OKP` key must name `Ed25519`, for client assertions and DPoP proofs alike.
- A `crit` header is refused: no JWS extension is understood (RFC 7515 section 4.1.11).
- `typ` is absent or `client-authentication+jwt`, with an optional `application/` prefix, compared case-insensitively. The header is read from the encoded token, because the parser reports a missing `typ` as `JWT`.
- `iss` and `sub` equal the `client_id`. `aud` is exactly `auth.oauth.issuer`, as a string or a one-element array, without normalization. The token endpoint URL that OpenID Connect Core suggests is refused.
- `exp` is required, and `exp - now` may not exceed `auth.client_assertion.max_lifetime` (300 by default) plus the clock skew `auth.dpop.clock_skew`. A present `iat` may not lie in the future beyond the skew, and `exp - iat` may not exceed the maximum lifetime. `nbf` is honored.
- The signature verifies with one of the keys `ClientKeySetProviderInterface::signingKeys()` returns for the assertion's `kid`. A key whose own `alg` differs is skipped.
- `jti` is required. After every other check passes, `ClientAssertionJtiStoreInterface::isReplay()` records it. `RedisClientAssertionJtiStore` uses the key `oauth:assertion-jti:` followed by the SHA-256 of the `client_id` and `jti`, apart from DPoP proof jtis, with a TTL of the assertion's remaining lifetime plus the skew, plus one second because times are checked in whole seconds.

The verifier logs which check failed and answers only `false`. `JwkPublicKeyConverter` turns a JWK into a verification key for both this verifier and `DpopProofValidator`.

`ClientKeySetProvider` serves the keys from the client's `ClientKeySource`: an inline JWK Set from `oauth_clients.jwks`, or the set published at `oauth_clients.jwks_uri`. `ClientJwksFetcher` fetches a published set through `PublicHttpClient`. The allowlist is the URI's own host, the address must be public, redirects are not followed, the budget is 3 seconds, and the body is read only up to `ClientKeySource::MAX_JWKS_BYTES` (64 KB). The fetched set must pass the same `ClientKeySource` rules as an inline one: at most 10 public asymmetric keys, with distinct `kid` values when there are several. Keys with a `use` other than `sig` are left out.

A fetched set is cached in `cache.app` for `auth.client_jwks.cache_ttl` seconds (3600 by default). The cache key hashes the client ID with the client's `updated_at`, so replacing the keys makes older entries unreachable, and a fetch that was running during the replacement cannot bring old keys back. Every fetch first takes a Redis lock (`SET NX EX`). The first fetch of an uncached set uses `oauth:client-jwks-fetch:`, so one fetch per client runs at a time and a concurrent request gets `503`. An assertion naming a `kid` the cached set lacks refetches under `oauth:client-jwks-refetch:`, at most once per client per `auth.client_jwks.refetch_interval` seconds (60 by default); within the interval the cached set answers.

A transport failure, timeout, refused destination, non-`200` answer or oversized body raises `DpopStoreUnavailableException`, caches nothing and leaves an earlier entry as it was. So does an unavailable lock, so nothing is fetched while Redis is down. A malformed set, or one without the assertion's key after the refetch, gives `invalid_client`.

Each assertion is single use, so a client signs a new one for the token request that follows a push. `TokenEndpointDpopListener` answers a `use_dpop_nonce` challenge before the controller runs, so that retry does not use the assertion up. A retry after a `503` may find it already used.

## Client Registration

Administrators manage device, public and confidential clients through the admin API (see [Admin — OAuth Clients](auth-inventory.md#admin--oauth-clients)) and the `app:oauth:client:*` console commands. Both dispatch the same messages, so the console has the same authority as a super administrator. `app:auth:setup-clients` still seeds the first-party SPA client, and users create their own personal access clients.

`ClientType` names what a client is registered for. `Client::getType()` derives it from the stored flags, in this order: `personal_access`, `first_party`, `device`, `confidential`, else `public`. `ClientType::isAdministered()` is true for `device`, `public`, and `confidential`. The rotate, key replacement and revoke handlers refuse any other client (`protected_client`). The client-management handlers raise the shared exceptions: `NotFoundException` for an unknown client (`404`), `ConflictException` (`409`) with reason `protected_client`, `no_secret`, `no_keys` or `revoked`, and `InvalidInputException` (`422`) with reason `invalid_registration` or `invalid_keys`. The reason reaches the admin API as `error.details.reason`. The list includes the first-party client and leaves out personal access clients.

`RegisterClientHandler` calls one factory per type. `Client::registerDevice()` takes a name only; a device client with redirect URIs is rejected. `Client::registerPublic()`, `Client::registerConfidential()` (with a secret) and `Client::registerConfidentialWithKeys()` (with a `ClientKeySource`) validate the redirect URIs in `Client::validRedirectUris()`:

- 1 to 10 URIs after trimming and removing duplicates, each at most 2000 characters, without whitespace or control characters;
- absolute, without a fragment, and without user name or password;
- `https` with a host; `http` only on a loopback host (`localhost`, `127.0.0.1`, `[::1]`; RFC 8252 section 7.3); or a private-use scheme in reverse domain form, containing a dot, such as `app.baander.tv:/callback` (RFC 8252 section 7.1). `javascript`, `data`, `file`, and `vbscript` are rejected.

A confidential client also has a `ClientAuthMethod`, fixed at registration: `client_secret_post` by default, or `private_key_jwt` with exactly one `ClientKeySource`. Switching between a secret and keys means registering a new client. `oauth_clients.token_endpoint_auth_method` stores the method, and the nullable `jwks` (`jsonb`) and `jwks_uri` (`text`) columns hold the key source. Check constraints tie them together: a client has a secret hash exactly when its method is `client_secret_post`, exactly one key source exactly when it is `private_key_jwt`, and is confidential exactly when it is not `none`.

An inline set holds at most 10 public asymmetric keys in at most 64 KB, each with its own `kid` when there are several, and an `OKP` key names `Ed25519`. A `jwks_uri` passes the syntactic half of the outbound fetch policy when it is given: `https` on port 443 and a dotted DNS name, with no IP literal, single label, trailing dot, credentials, fragment, whitespace or backslash.

Registration answers a failure with `invalid_registration` and key replacement with `invalid_keys`.

A client name is trimmed and at most 100 characters. A rejected registration raises `InvalidInputException` with reason `invalid_registration`, which the admin API answers with `422`.

`ClientSecret` generates a confidential client's secret: 32 random bytes, base64url-encoded without padding (43 characters). The client stores only the lower-case hex SHA-256 digest, in `oauth_clients.secret_hash`. `OAuthClientAuthenticator` checks a presented `client_secret` through `Client::authenticatesWith()`, which compares digests in constant time with `hash_equals()`. A fast hash is enough here: a secret with 256 random bits cannot be guessed, so a slow password hash would add no resistance, only CPU cost on every token request. The plain secret exists only in `RegisteredClientDTO`, between generation and the one response or command output that shows it; that response carries `Cache-Control: no-store`. `RotateClientSecretHandler` refuses a client without a secret (`no_secret`) or a revoked one (`revoked`), both `409` in the admin API. The new secret takes effect at once, and tokens already issued stay valid.

`ReplaceClientKeysHandler` gives a `private_key_jwt` client a new inline JWK Set or `jwks_uri`, and may switch between the two, since the method stays the same. It refuses a client without keys (`no_keys`) or a revoked one (`revoked`) with `409`, and invalid keys (`invalid_keys`) with `422`. `Client::replaceKeys()` also sets the client's `updated_at`, so `ClientKeySetProvider` stops using a cached set at once. Replacing a `jwks_uri` with the same URI is accepted, and it is how an operator withdraws a key at once: a key removed from the published set otherwise stays usable from the cache until `auth.client_jwks.cache_ttl` expires, because only an unknown `kid` triggers a refetch.

`ClientRevoker` revokes a client and every access and refresh token issued to it in one transaction, for both `RevokeRegisteredClientHandler` and the personal access `RevokeClientHandler`. The client flag alone is not enough, because the resource server checks each access token's own revocation flag. Issuance takes a shared lock on the client row, so revocation and issuance cannot interleave. Revoking a revoked client succeeds again. A revoked client fails `OAuthClientAuthenticator` like an unknown one.
