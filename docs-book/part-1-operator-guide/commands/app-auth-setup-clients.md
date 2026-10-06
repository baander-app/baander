# app:auth:setup-clients

Create the first-party OAuth2 clients for the SPA and Electron apps. Run this during initial setup to enable login.

## Quick start

```bash
make exec cmd="php bin/console app:auth:setup-clients"
```

## Details

The command creates two OAuth2 clients:

- **Bånder SPA** — for the web frontend
- **Bånder Electron** — for the desktop app

Both are first-party clients. They use **fixed, deterministic public IDs**, so they resolve correctly even after a full database reset:

| Client | Public ID |
|--------|-----------|
| Bånder SPA | `baander_dev_spa_00001` |
| Bånder Electron | `baander_dev_elc_00001` |

These IDs are already set in `.env.example` as `AUTH_SPA_CLIENT_ID` and `AUTH_ELECTRON_CLIENT_ID`. Password and passkey login issue every token pair to the client named by `AUTH_SPA_CLIENT_ID`. If that client row is missing or revoked, login fails with a server error.

The command is **idempotent**: it looks up each client by its fixed public ID and creates it only if missing. Run it freely — including after `app:dev:setup --fresh` or any database wipe.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Clients ready (created or already present) |
| 1 | Something went wrong — check the error message |

## Tips

- Safe to run repeatedly — existing clients are left untouched.
- The public IDs are identifiers, not secrets. Client confidentiality comes from secrets, not the public ID.
