# app:auth:setup-clients

Create OAuth2 password grant clients for the SPA and Electron apps. Run this once during initial setup to enable authentication.

## Quick start

```bash
make exec cmd="php bin/console app:auth:setup-clients"
```

## Details

The command creates two OAuth2 password clients:

- **Bånder SPA** — for the web frontend
- **Bånder Electron** — for the desktop app

Both are first-party clients with password grant enabled. After creation, the command prints a table with each client's public ID and instructs you to add them to your `.env` file:

```env
AUTH_SPA_CLIENT_ID=<spa-public-id>
AUTH_ELECTRON_CLIENT_ID=<electron-public-id>
```

Without these values set, users cannot authenticate.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Clients created successfully |
| 1 | Something went wrong — check the error message |

## Tips

- Only run this once during initial setup. Running it again creates duplicate clients.
- Copy the printed environment variables into your `.env` file immediately — the IDs are not stored anywhere else.
- If you accidentally create duplicates, the system still works — the most recently created client for each app is used.
