# app:oauth:client:create

Register an OAuth client that is not one of Baander's own apps: a device client such as a TV app, a public client such as a native or browser app, or a confidential client that authenticates with a secret or with its own signing keys. The command does what `POST /api/admin/oauth/clients` does in the admin API.

## Quick start

The Make examples below target the checkout's development preview. For an
installation, use the `dc` function from
[Installation](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec web php bin/console …`.

Register a device client:

```bash
make exec cmd="php bin/console app:oauth:client:create 'Living room TV' --type device"
```

Register a public client with two redirect URIs:

```bash
make exec cmd="php bin/console app:oauth:client:create 'Baander Player' --type public --redirect-uri https://player.baander.app/callback --redirect-uri http://127.0.0.1/callback"
```

Register a confidential client that authenticates with a secret:

```bash
make exec cmd="php bin/console app:oauth:client:create 'Scrobble bridge' --type confidential --redirect-uri https://scrobble.baander.app/oauth/callback"
```

Register a confidential client that signs its requests with keys it publishes:

```bash
make exec cmd="php bin/console app:oauth:client:create 'Scrobble bridge' --type confidential --redirect-uri https://scrobble.baander.app/oauth/callback --auth-method private_key_jwt --jwks-uri https://scrobble.baander.app/.well-known/jwks.json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `name` | Yes | Name the user sees when approving the client; at most 100 characters |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--type`, `-t` | — | `device`, `public` or `confidential`. Required. |
| `--redirect-uri`, `-r` | — | Redirect URI of a public or confidential client. Repeat the option for each URI. |
| `--auth-method` | `client_secret_post` | How a confidential client authenticates: `client_secret_post` with a generated secret, or `private_key_jwt` with its own signing keys. Only for confidential clients. |
| `--jwks-file` | — | Path to a file holding a `private_key_jwt` client's public keys as a JWK Set |
| `--jwks-uri` | — | An `https` URI on port 443 where a `private_key_jwt` client publishes its JWK Set |
| `--json` | — | Print only the new client, as the admin API's `data` payload, in JSON |

## Details

The client type decides which grant the client uses and what it must be registered with:

| Type | Grant | Redirect URIs | Secret |
|------|-------|---------------|--------|
| `device` | Device authorization (RFC 8628) | None allowed | None |
| `public` | Authorization code with PKCE | 1 to 10 | None |
| `confidential` | Authorization code with PKCE | 1 to 10 | Generated with `client_secret_post`; none with `private_key_jwt` |

A redirect URI must be absolute and carry no fragment and no user name or password. It must use `https`, plain `http` on a loopback host (`localhost`, `127.0.0.1` or `[::1]`), or a native app's private-use scheme in reverse domain form, such as `app.baander.tv:/callback`. Each URI may be up to 2000 characters, and duplicates are dropped. At the authorization endpoint a request must name a registered URI exactly. The one exception is a loopback URI, which matches on any port, because native apps listen on a port the system picks.

A confidential client's authentication method is fixed at registration. With `client_secret_post`, the default, Baander generates a secret. With `private_key_jwt` the client signs a short-lived assertion with its own private key, and Baander checks it against the public keys registered here. Give exactly one key source: `--jwks-file` for an inline set, or `--jwks-uri` for a set the client publishes and rotates itself. The keys must meet the rules that [app:oauth:client:replace-keys](app-oauth-client-replace-keys.md) describes; that command also replaces them later. To switch a client between a secret and keys, register a new client.

The command prints the client ID, name, type, authentication method, redirect URIs and revocation state, and the `jwks_uri` when there is one. For a `client_secret_post` client it also prints the client secret, followed by a warning to store it now. Baander keeps only a SHA-256 digest of the secret and cannot show it again. If the secret is lost, issue a new one with [app:oauth:client:rotate-secret](app-oauth-client-rotate-secret.md).

With `--json` the command prints only the client as the API's `data` object, the same resource `POST /api/admin/oauth/clients` returns: `clientId`, `name`, `type`, `authMethod`, `jwksUri`, `redirectUris`, `revoked`, `createdAt`, `updatedAt` and `clientSecret`. `clientSecret` is `null` for device, public and `private_key_jwt` clients, and `jwksUri` is `null` unless the client publishes its keys. The JSON is the only copy of a confidential client's secret, so store it before the output is discarded.

The client ID is the `client_id` the client sends to the authorization server. It is an identifier, not a secret.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Client registered |
| 1 | An error occurred; the message says why |
| 2 | `--type` is missing or not `device`, `public` or `confidential`, the JWK Set file cannot be read or holds no JSON object, or the name, redirect URIs, authentication method or keys were rejected, as the API rejects them with 422; nothing was registered |

## Tips

- List registered clients with [app:oauth:client:list](app-oauth-client-list.md).
- Super administrators can register clients in the admin API as well; both paths apply the same rules.
- The first-party client that the web and desktop apps use is created by [app:auth:setup-clients](app-auth-setup-clients.md), not by this command.
