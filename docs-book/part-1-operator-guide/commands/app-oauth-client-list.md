# app:oauth:client:list

List the OAuth clients of the authorization server. The list holds the first-party client and every device, public and confidential client, revoked ones included. Users' personal access clients are left out. The command shows what `GET /api/admin/oauth/clients` returns in the admin API.

## Quick start

```bash
make exec cmd="php bin/console app:oauth:client:list"
```

## Details

The command prints a table with one row per client:

| Column | Content |
|--------|---------|
| Client ID | The `client_id` the client sends |
| Name | The name users see when they approve the client |
| Type | `device`, `public`, `confidential` or `first_party` |
| Redirect URIs | One URI per line, or `-` for a device client |
| Revoked | `yes` or `no` |
| Created | Registration time in ISO 8601 format |

Secrets are never listed. Baander stores only their digests.

The `first_party` client is the one password and passkey login issue tokens to. It is listed for completeness; the other `app:oauth:client:*` commands refuse to change it. When no client exists, the command prints `No OAuth clients are registered.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The clients could not be read; the message says why |
