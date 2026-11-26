# Manage OAuth clients

Register an application before it requests access for a user. This page covers registration, keys, secrets, revocation and the approval pages users see. For the authority of a delegated token, see [Delegated tokens and scopes](../configuration/authentication-and-limits.md#delegated-tokens-and-scopes).

## OAuth Clients

Baander's web and desktop apps sign in through one first-party OAuth client, which [app:auth:setup-clients](../commands/app-auth-setup-clients.md) creates during setup. Any other application that wants to act for a user, such as a TV app, a native player or a server-side integration, gets its tokens from Baander's OAuth 2.0 authorization server, and it must be registered as a client first. The client's ID is what the application sends as `client_id`.

Registration alone grants nothing: a signed-in user still has to approve the application before it gets tokens for them. Those tokens are delegated: they reach only what the scopes the user approved cover, such as browsing and playing the user's libraries or managing their playlists, and never administration or account security. See [Delegated tokens and scopes](../configuration/authentication-and-limits.md#delegated-tokens-and-scopes).

### Client types

| Type | For | Created by |
|------|-----|------------|
| `first_party` | Baander's own web and desktop apps; password and passkey login issue tokens to it | `app:auth:setup-clients` |
| `device` | Devices without a browser or keyboard, such as TV apps; uses the device authorization grant (RFC 8628) and has no redirect URIs | An administrator |
| `public` | Native and browser apps that cannot keep a secret; uses the authorization code grant with PKCE | An administrator |
| `confidential` | Server-side applications that can keep a secret; uses the authorization code grant with PKCE and authenticates with a client secret or with its own signing keys | An administrator |
| `personal_access` | A user's own scripts and tools | The user |

Administrators manage device, public and confidential clients. The first-party client and personal access clients are outside their reach: the commands and admin endpoints below list the first-party client but refuse to change it, and they do not show personal access clients at all. Users list and revoke their own personal access clients. A scheduled job, **Purge revoked personal access clients**, deletes the revoked ones and their tokens every day at 03:45 UTC; [`app:oauth:client:purge-revoked`](../commands/app-oauth-client-purge-revoked.md) runs the same purge on demand.

### Who can manage clients

Anyone with console access can run the `app:oauth:client:*` commands. In the admin panel and the admin API, an administrator (`ROLE_ADMIN`) can list clients, and only a super administrator (`ROLE_SUPER_ADMIN`) can register a client, rotate its secret, replace its keys or revoke it. The admin panel's **Security → OAuth Clients** tab (`/admin/security?tab=oauth-clients`) calls the admin API. The commands and the endpoints run the same application handlers, so they apply the same rules:

| Action | Command | Admin panel | Admin API |
|--------|---------|-------------|-----------|
| List clients | [app:oauth:client:list](../commands/app-oauth-client-list.md) | The OAuth Clients tab | `GET /api/admin/oauth/clients` |
| Register a client | [app:oauth:client:create](../commands/app-oauth-client-create.md) | **Create client** | `POST /api/admin/oauth/clients` |
| Rotate a confidential client's secret | [app:oauth:client:rotate-secret](../commands/app-oauth-client-rotate-secret.md) | **Rotate secret** on the client's row | `POST /api/admin/oauth/clients/{clientId}/rotate-secret` |
| Replace a key client's keys | [app:oauth:client:replace-keys](../commands/app-oauth-client-replace-keys.md) | **Replace keys** on the client's row | `POST /api/admin/oauth/clients/{clientId}/keys` |
| Revoke a client and its tokens | [app:oauth:client:revoke](../commands/app-oauth-client-revoke.md) | **Revoke** on the client's row | `POST /api/admin/oauth/clients/{clientId}/revoke` |

### Registering a client

Register a device client with a name the user will recognize when approving it:

```bash
dc exec web php bin/console app:oauth:client:create 'Living room TV' --type device
```

Public and confidential clients need at least one redirect URI, and at most 10. This is where Baander sends the user back after the decision:

```bash
dc exec web php bin/console app:oauth:client:create 'Baander Player' --type public --redirect-uri https://player.baander.app/callback
```

A redirect URI must be absolute, without a fragment or credentials, and use `https`, plain `http` on a loopback host (`localhost`, `127.0.0.1` or `[::1]`), or a private-use scheme in reverse domain form such as `app.baander.tv:/callback`. A pushed authorization request must name a registered URI exactly. A loopback URI also matches on any port, because native apps listen on a port the system picks. Device clients have no redirect URIs. See [app:oauth:client:create](../commands/app-oauth-client-create.md) for the full rules.

### How confidential clients authenticate

A confidential client authenticates in one of two ways, chosen at registration and fixed afterwards:

- **With a secret** (`client_secret_post`, the default). Baander generates the secret; see [Client secrets](#client-secrets). The application sends it in the request body or with HTTP Basic authentication (`client_secret_basic`), never both at once.
- **With its own keys** (`private_key_jwt`). The application signs a short-lived token with a private key that never leaves it, and Baander checks the signature against the client's public keys. No shared secret exists that could leak from Baander.

To move an application from a secret to keys, or back, register a new client and revoke the old one.

### Client keys

Register a key client when the application can sign with its own key pair, and give Baander its public keys in one of two forms:

- **An inline key set.** You give Baander the application's public JWK Set when you register the client. Use this when the application's operator hands you its keys and rotates them rarely.
- **A key set URL** (`jwks_uri`). The application publishes its public keys at an `https` address, and Baander fetches them from there. Use this when the application rotates its own keys: it publishes a new key, starts signing with it, and Baander picks the key up within a minute without an administrator.

In the admin panel, choose the confidential type and the key authentication method in **Create client**, then give the key set or its URL. The admin API takes the same choice as `authMethod` with `jwks` or `jwksUri`, and [app:oauth:client:create](../commands/app-oauth-client-create.md) takes `--auth-method` with `--jwks-file` or `--jwks-uri`.

A key set holds at most 10 public keys and 64 KB, and when it holds several keys, each needs its own `kid`. Baander refuses private and symmetric keys, so a key set never carries secret material into Baander. An `OKP` key must use the `Ed25519` curve. A key set URL must use `https` on the default port, name a host by a DNS name with at least one dot rather than an IP address, and carry no user name, password or fragment.

Baander fetches it only from a public address of that host, does not follow redirects, and gives up after 3 seconds. Baander caches the fetched keys, so the URL is needed only for the first fetch, after the cache expires, and when the application signs with a key Baander has not seen. If the URL cannot be reached then, the application's request answers `503` and it should retry; nothing is cached from a failed fetch.

To change the keys, use **Replace keys** on the client's row, the admin API's `POST /api/admin/oauth/clients/{clientId}/keys`, or [app:oauth:client:replace-keys](../commands/app-oauth-client-replace-keys.md). Replacing may switch a client between an inline set and a URL. The new keys apply to the application's next request, and a cached copy of the old set is never used again. Tokens the application already holds stay valid.

A key the application removes from its published set is not withdrawn at once: Baander keeps using its cached copy, for up to an hour by default, because it fetches early only for a key it has not seen. If a key leaked, replace the client's keys with the same URL right after the application removes the key; Baander then fetches the set again at the next request. A secret client has no keys to replace, and a key client has no secret to rotate.

The settings that bound key clients are in [Client assertions and key sets](../configuration/authentication-and-limits.md#client-assertions-and-key-sets).

### Client secrets

A `client_secret_post` client gets a generated secret when it is registered. The command output, the admin panel or the API response shows that secret once. Baander stores only its SHA-256 digest, so nobody can read the secret back later, not even an administrator. Copy it into the client's configuration straight away.

If the secret is lost or may have leaked, rotate it. The old secret stops working at once, while the access and refresh tokens the client already holds stay valid. The client needs the new secret before its next token request. Device, public and key clients have no secret.

### Revoking a client

Revoking a client also revokes every access and refresh token issued to it, in the same transaction, so every user signed in through that client is signed out of it at once. Afterwards the authorization server treats the client as unknown. A revoked client stays in the list, marked as revoked, and cannot be restored; register a new client to let the application in again. Revoking an already revoked client succeeds again, so a retry is harmless.

### What users see

The approval pages belong to the web app. They let a signed-in user inspect and
approve the access a client requests.

#### Consent page

For the authorization code grant, the application first pushes its request directly to Baander, server to server. Baander returns a short-lived reference to that request. It then sends the user's browser to `APP_URL/oauth/authorize`, the authorization endpoint that the metadata document at `/.well-known/oauth-authorization-server` advertises, with only its client ID and that reference. The request's details, such as the scopes and the redirect URI, stay out of the browser's address bar, where they could be changed.

The page shows the client's name and one line for each scope it would get. A request that asks for no scope Baander grants is refused before the user sees it. The user approves or denies, and the page sends the browser back to the client's redirect URI with an authorization code or an `access_denied` error.

The reference works for 5 minutes by default (`auth.par.request_uri_ttl`), long enough to sign in first, and only for one decision. If the client is unknown or revoked, or the reference is unknown, expired or already used, the page shows the error and does not redirect; the application has to start over. The authorization code itself lives 60 seconds by default (`auth.auth_code.ttl`) and works only for the application that holds the key its pushed request named. Baander keeps no record of earlier consent.

The page asks the user about every request; it approves none on its own. For one of the user's own personal access clients it also names the client type and the local address, port included, that the answer returns to, so the user can tell which program asks. Another user's personal access client is refused. The first-party client is not an OAuth client: the push, the consent page and the token endpoint refuse it, so Baander's own apps get tokens only by signing in with a password or passkey.

Baander stores authorization codes and device codes only as SHA-256 hashes, so a copy of the database cannot redeem them.

#### Device page

A device client shows a short user code, such as `BCDF-GHJK`, and the address `APP_URL/device`. It may also offer a QR code of that address with the code filled in. The user opens the page, enters the code if it is not filled in already, sees which client is asking and for which scopes, and approves or denies. Case, spaces and dashes in the code do not matter. The code expires after 15 minutes by default (`auth.device_code.ttl`).

On approval the device receives its tokens at its next poll, and the user gets a Security notification about the approval.

The page addresses are the `auth.oauth.authorization_page_uri` and `auth.device.verification_uri` parameters; see [Authorization server](../configuration/authentication-and-limits.md#authorization-server).

#### Expired codes

Authorization codes, device codes and pushed authorization requests are deleted
when they have been expired for more than an hour. This applies whether they were
used, denied or never touched. The hour lets a device that is still polling learn that its code expired. A scheduled job, **Purge expired OAuth codes**, does this every day at 03:30 UTC. It is created on install and can be rescheduled or paused in the scheduler admin. [`app:oauth:purge-codes`](../commands/app-oauth-purge-codes.md) runs the same purge on demand.
