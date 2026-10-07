# app:oauth:client:create

Register an OAuth client that is not one of Baander's own apps: a device client such as a TV app, a public client such as a native or browser app, or a confidential client that authenticates with a secret. The command does what `POST /api/admin/oauth/clients` does in the admin API.

## Quick start

Register a device client:

```bash
make exec cmd="php bin/console app:oauth:client:create 'Living room TV' --type device"
```

Register a public client with two redirect URIs:

```bash
make exec cmd="php bin/console app:oauth:client:create 'Baander Player' --type public --redirect-uri https://player.baander.app/callback --redirect-uri http://127.0.0.1/callback"
```

Register a confidential client:

```bash
make exec cmd="php bin/console app:oauth:client:create 'Scrobble bridge' --type confidential --redirect-uri https://scrobble.baander.app/oauth/callback"
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

## Details

The client type decides which grant the client uses and what it must be registered with:

| Type | Grant | Redirect URIs | Secret |
|------|-------|---------------|--------|
| `device` | Device authorization (RFC 8628) | None allowed | None |
| `public` | Authorization code with PKCE | 1 to 10 | None |
| `confidential` | Authorization code with PKCE | 1 to 10 | Generated |

A redirect URI must be absolute and carry no fragment and no user name or password. It must use `https`, plain `http` on a loopback host (`localhost`, `127.0.0.1` or `[::1]`), or a native app's private-use scheme in reverse domain form, such as `app.baander.tv:/callback`. Each URI may be up to 2000 characters, and duplicates are dropped. At the authorization endpoint a request must name a registered URI exactly. The one exception is a loopback URI, which matches on any port, because native apps listen on a port the system picks.

The command prints the client ID, name, type, redirect URIs and revocation state. For a confidential client it also prints the client secret, followed by a warning to store it now. Baander keeps only a SHA-256 digest of the secret and cannot show it again. If the secret is lost, issue a new one with [app:oauth:client:rotate-secret](app-oauth-client-rotate-secret.md).

The client ID is the `client_id` the client sends to the authorization server. It is an identifier, not a secret.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Client registered |
| 1 | The name or redirect URIs were rejected, or another error occurred; the message says why |
| 2 | `--type` is missing or not `device`, `public` or `confidential` |

## Tips

- List registered clients with [app:oauth:client:list](app-oauth-client-list.md).
- Super administrators can register clients in the admin API as well; both paths apply the same rules.
- The first-party client that the web and desktop apps use is created by [app:auth:setup-clients](app-auth-setup-clients.md), not by this command.
