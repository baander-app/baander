# app:auth:setup-clients

Create the first-party OAuth client that password and passkey login issue tokens to. Run this during initial setup to enable login.

## Quick start

```bash
make exec cmd="php bin/console app:auth:setup-clients"
```

## Details

The command creates one first-party OAuth2 client, **Bånder SPA**. The web frontend and the Electron desktop app both log in through it. The client has a **fixed, deterministic public ID**, so it resolves correctly even after a full database reset:

| Client | Public ID |
|--------|-----------|
| Bånder SPA | `baander_dev_spa_00001` |

This ID is already set in `.env.example` as `AUTH_SPA_CLIENT_ID`. Password and passkey login issue every token pair to the client named by `AUTH_SPA_CLIENT_ID`. If that client row is missing or revoked, login fails with a server error.

The command is **idempotent**: it looks up the client by its fixed public ID and creates it only if it is missing. Run it freely, including after `app:dev:setup --fresh` or any database wipe.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Client ready (created or already present) |
| 1 | Something went wrong — check the error message |

## Tips

- Safe to run repeatedly. An existing client is left untouched.
- The public ID is an identifier, not a secret.
- The command creates no other OAuth clients. Register device, public and confidential clients with [app:oauth:client:create](app-oauth-client-create.md).
- The `app:oauth:client:*` commands list the first-party client but do not rotate or revoke it.
