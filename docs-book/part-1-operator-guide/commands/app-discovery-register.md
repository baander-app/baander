# app:discovery:register

Register a self-hosted server for discovery and pairing. The command runs the same use case as `POST /api/discovery/register` in the API.

## Quick start

```bash
make exec cmd="php bin/console app:discovery:register https://music.baander.app 'Home Server' 1.0.0"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `url` | Yes | The server's base URL, `http` or `https` |
| `name` | Yes | The server's display name |
| `version` | Yes | The server's version |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the server, as the API's `data` payload, in JSON |

## Details

The server creates the API key itself when it first registers a URL. The command never prints the key and the API never returns it.

Registering a URL that is already registered keeps the server's public ID and its key, and updates the version and the last heartbeat. The name is not changed.

With `--json` the command prints only the server as the API's `data` object: `uuid`, `publicId`, `serverUrl`, `name`, `version`, `status`, `lastHeartbeatAt` and `createdAt`.

The command acts with full authority and needs no login.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Server registered, or already registered and updated |
| 1 | Another error occurred; the message says why |
| 2 | The URL is not a valid `http` or `https` URL |
