# OAuth Client Authentication and Administration

A client uses the authentication method chosen at registration. This page gives the credential and key requirements, followed by the administrator endpoints. For grant requests, see [OAuth Client Flows](api-oauth.md).

### Client Authentication

The pushed authorization request endpoint, the token endpoint and the device authorization endpoint authenticate the client the same way. Every request names its client in `client_id`, or in an HTTP Basic `Authorization` header. Each client has one authentication method, fixed at registration and listed in the admin API as `authMethod`:

| Method | Client | What the request adds |
|--------|--------|-----------------------|
| `none` | Public, device, first-party and personal access clients | Nothing; `client_id` alone |
| `client_secret_post` | Confidential client registered with a secret | `client_secret` in the body, or the client ID and secret in HTTP Basic (`client_secret_basic`) |
| `private_key_jwt` | Confidential client registered with keys | `client_assertion` and `client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer` |

A request that presents a method other than the registered one, or more than one, gets `401` `invalid_client` (RFC 6749 section 2.3). So does a request from an unknown, malformed or revoked client, so the answer does not reveal which it was.

#### HTTP Basic

A secret client may send `Authorization: Basic` with its client ID and secret, each form-urlencoded before they are joined with a colon (RFC 6749 section 2.3.1). The header then names the client, and a `client_id` in the body may only repeat it. A secret goes by exactly one means: Basic together with a body `client_secret` or with an assertion, and Basic from a public or key client, get `invalid_client`, as does a malformed header. At the token and pushed authorization request endpoints, every `invalid_client` answer to a request that sent Basic credentials carries a `WWW-Authenticate: Basic` challenge (RFC 6749 section 5.2). Both ways of sending the secret belong to the one stored method, `client_secret_post`.

#### Client assertions

A `private_key_jwt` client signs a JWT (RFC 7523 as updated by RFC 10070) with one of its registered keys:

- The `alg` header is one of `EdDSA`, `ES256`, `ES384`, `ES512`, `PS256`, `RS256`, `RS384` or `RS512`; `none` and HMAC algorithms are refused. The metadata lists them as `token_endpoint_auth_signing_alg_values_supported`. An RSA key, for `PS256` as for the `RS` algorithms, must have a modulus of 2048 to 4096 bits and the public exponent 65537, and an `EdDSA` key must be an Ed25519 `OKP` key.
- The RSA algorithms sign client assertions only. A DPoP proof is signed with `EdDSA`, `ES256`, `ES384` or `ES512`, which the metadata lists as `dpop_signing_alg_values_supported`, so a client whose assertion key is an RSA key signs its proofs with a separate EC or Ed25519 key. A `DPoP` header longer than 4096 bytes is refused.
- The `typ` header is absent or `client-authentication+jwt`, with or without the `application/` prefix, in any case. Any other type is refused.
- A `crit` header is refused, because Baander understands no JWS extension (RFC 7515 section 4.1.11).
- The `kid` header selects the key. Without it, every registered signing key is tried. A `jwks_uri` client needs `kid` to rotate keys, because only an unknown `kid` makes Baander fetch the set again before the cache expires.
- `iss` and `sub` are the `client_id`.
- `aud` is exactly the issuer identifier, `APP_URL`, as a string or as a one-element array. The token endpoint or pushed request endpoint URL is refused, although OpenID Connect Core suggests the token endpoint. Nothing is normalized, so a trailing slash makes the audience differ.
- `exp` is required and may lie at most `auth.client_assertion.max_lifetime` seconds (300 by default) ahead, plus the clock skew `auth.dpop.clock_skew`. When `iat` is present, it may not lie in the future beyond the skew, and `exp - iat` may not exceed the maximum lifetime either. `nbf` is honored.
- `jti` is required and accepted once per client. Sign a new assertion for every request, including the token request that follows a push.

An assertion for a client with the ID `Kp3xR8vW2mQ7nL5tY9bZc` on `https://baander.app`, before signing:

```json
{
  "alg": "ES256",
  "typ": "client-authentication+jwt",
  "kid": "player-2026-11"
}
```

```json
{
  "iss": "Kp3xR8vW2mQ7nL5tY9bZc",
  "sub": "Kp3xR8vW2mQ7nL5tY9bZc",
  "aud": "https://baander.app",
  "iat": 1795086000,
  "exp": 1795086060,
  "jti": "4f0c6a6e-3a1d-4c55-9a59-7d1b0f1c2e88"
}
```

The signed assertion goes in the request body:

```
POST /api/oauth/par
Content-Type: application/x-www-form-urlencoded
DPoP: <proof with nonce>

response_type=code&client_id=Kp3xR8vW2mQ7nL5tY9bZc&client_assertion_type=urn%3Aietf%3Aparams%3Aoauth%3Aclient-assertion-type%3Ajwt-bearer&client_assertion=<signed JWT>&redirect_uri=https://sync.baander.app/callback&scope=library&state=<state>&code_challenge=<challenge>&code_challenge_method=S256
```

An assertion that fails any check gets `401` `invalid_client`; the server logs which check failed but does not say so in the answer. While the store that records assertion `jti` values is unavailable, the endpoint answers `503` with `Retry-After`. The assertion may have been used up by then, so retry with a newly signed one. A DPoP nonce challenge (`use_dpop_nonce`) is answered before the assertion is checked, so the retry may send the same assertion.

#### Client keys

A `private_key_jwt` client registers its public keys in one of two ways:

- **Inline JWK Set.** The administrator stores the set with the client. It holds at most 10 keys and 64 KB, only public asymmetric keys (no `oct` keys and no private members), and when it holds more than one key, each has its own `kid`. An `OKP` key must name the `Ed25519` curve, and an RSA key must have a modulus of at most 4096 bits and the public exponent 65537.
- **`jwks_uri`.** The client publishes the set at an `https` URI on port 443, without credentials. When an administrator registers or changes it, the URI must name a DNS host with at least one dot. An IP literal, a single label such as `localhost`, a name ending in a dot, a fragment, whitespace and a backslash are refused. Baander fetches it from a public address on that host alone, without following redirects, within 3 seconds, and stops reading after 64 KB; the set must meet the rules of an inline one. Only keys with `use` absent or `sig` count.

A fetched set is cached for `auth.client_jwks.cache_ttl` seconds (3600 by default). An assertion whose `kid` the cached set lacks makes Baander fetch the set again, at most once per client every `auth.client_jwks.refetch_interval` seconds (60 by default), so a client can rotate its keys on its own: publish the new key, then sign with it. Replacing a client's keys through the admin API or the console makes the cached set unreachable at once. A fetch that fails, times out, answers anything but `200` or sends more than 64 KB gets `503` with `Retry-After` and caches nothing. So does a request that needs a fetch while another request is fetching the same client's uncached set, or while Redis, which holds the fetch lock, is unavailable. A set that is malformed, or that lacks the assertion's key after the refetch, gets `invalid_client`.

Withdrawing a key works differently from adding one. A key the client removes from its published set stays in Baander's cached copy, and assertions signed with it keep working until the cache expires, up to `auth.client_jwks.cache_ttl` seconds later, because only an unknown `kid` triggers a refetch. To end a leaked or retired key at once, an administrator replaces the client's keys, giving the same `jwks_uri` again if it is unchanged: the next assertion then fetches the set afresh.

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
      "authMethod": "none",
      "jwksUri": null,
      "redirectUris": [],
      "revoked": false,
      "createdAt": "2026-10-07T12:00:00+00:00",
      "updatedAt": "2026-10-07T12:00:00+00:00"
    }
  ]
}
```

`type` is `device`, `public`, `confidential`, or `first_party`. `authMethod` is the client's [authentication method](#client-authentication), and `jwksUri` is the key set address of a `private_key_jwt` client that publishes its keys. The first-party login client is listed but cannot be changed through these endpoints.

`POST /api/admin/oauth/clients` registers a client:

```json
{
  "name": "Baander Player",
  "type": "confidential",
  "redirectUris": ["https://player.baander.app/callback"]
}
```

`name` is at most 100 characters. `type` is `device`, `public`, or `confidential`. `redirectUris` holds 1 to 10 URIs for public and confidential clients and must be omitted or empty for device clients. Each URI must be absolute, without a fragment or credentials, and use `https`, `http` on a loopback host, or a private-use scheme in reverse domain form.

A confidential client takes `authMethod`: `client_secret_post` (the default) or `private_key_jwt`. Device and public clients leave it out. A `private_key_jwt` client also takes exactly one key source, `jwks` with an inline JWK Set or `jwksUri` with the address of a published one; see [Client keys](#client-authentication) for the rules:

```json
{
  "name": "Baander Sync",
  "type": "confidential",
  "redirectUris": ["https://sync.baander.app/callback"],
  "authMethod": "private_key_jwt",
  "jwksUri": "https://sync.baander.app/.well-known/jwks.json"
}
```

The answer is `201` with the client and `clientSecret`. For a confidential client registered with the default method:

```json
{
  "data": {
    "clientId": "V1StGXR8_Z5jdHi6B-myT",
    "name": "Baander Player",
    "type": "confidential",
    "authMethod": "client_secret_post",
    "jwksUri": null,
    "redirectUris": ["https://player.baander.app/callback"],
    "revoked": false,
    "createdAt": "2026-10-07T12:00:00+00:00",
    "updatedAt": "2026-10-07T12:00:00+00:00",
    "clientSecret": "<43-character secret>"
  }
}
```

`clientSecret` is a `client_secret_post` client's secret, shown in this response only; Baander stores its SHA-256 digest. It is `null` for device, public and `private_key_jwt` clients. Invalid input gets `422`: a validation error for a missing or malformed field, or `error.details.reason` `invalid_registration` when the registration rules reject the client, for example its redirect URIs or its key source.

`POST /api/admin/oauth/clients/{clientId}/rotate-secret` gives a confidential client a new secret and answers `200` in the same shape, with the new `clientSecret`. The old secret stops working at once; issued tokens stay valid. `POST /api/admin/oauth/clients/{clientId}/revoke` revokes the client together with every access and refresh token issued to it and answers `200` with the client, without `clientSecret`. Revoking a revoked client succeeds again.

`POST /api/admin/oauth/clients/{clientId}/keys` replaces a `private_key_jwt` client's keys with the `jwks` or `jwksUri` in the body, and answers `200`. It may switch the client from one key source to the other; the method stays `private_key_jwt`. The new keys apply to the next assertion, and a cached copy of a published set is no longer used. Invalid keys get `422` with `error.details.reason` `invalid_keys`.

All three take the `client_id` as `{clientId}`; an unknown one gets `404`. A first-party or personal access client gets `409` with `error.details.reason` `protected_client`. Rotation also answers `409` for a client without a secret (`no_secret`), which includes every `private_key_jwt` client, and key replacement for a client without keys (`no_keys`); both answer `409` for a revoked client (`revoked`). Responses that carry a secret also carry `Cache-Control: no-store`.
