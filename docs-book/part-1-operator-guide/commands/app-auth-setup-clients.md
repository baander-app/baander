# app:auth:setup-clients

Create the first-party OAuth client that password and passkey login issue tokens to. Run this during initial setup to enable login.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

```bash
make exec cmd="php bin/console app:auth:setup-clients"
```

## Options

| Option | Description |
|--------|-------------|
| `--origin=ORIGIN` | Redirect URI given to the client when the command creates it. An HTTP or HTTPS origin without credentials, path, query or fragment; a trailing slash is removed. Default: `https://baander.test`. An existing client keeps its redirect URIs. |

## Details

The command creates one first-party OAuth2 client, **Bånder SPA**, through which the web frontend and the Electron desktop app both log in. The client has a **fixed, deterministic public ID**, so it resolves correctly even after a full database reset:

| Client | Public ID |
|--------|-----------|
| Bånder SPA | `baander_dev_spa_00001` |

This ID is already set in `.env.example` as `AUTH_SPA_CLIENT_ID`. Password and passkey login issue every token pair to the client named by `AUTH_SPA_CLIENT_ID`. If that client row is missing or revoked, login fails with a server error.

The command is **idempotent**: it looks up the client by its fixed public ID and creates it only if it is missing. Run it again whenever you need to, including after `app:dev:setup --fresh` or any database wipe.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Client ready (created or already present) |
| 1 | An error occurred; the message says why |
| 2 | `--origin` is not an HTTP or HTTPS origin, or carries credentials, a path, a query or a fragment |

## Tips

- Safe to run repeatedly. An existing client is left untouched.
- The public ID is an identifier, not a secret.
- The command creates no other OAuth clients. Register device, public and confidential clients with [app:oauth:client:create](app-oauth-client-create.md).
- The `app:oauth:client:*` commands list the first-party client but do not rotate or revoke it.
