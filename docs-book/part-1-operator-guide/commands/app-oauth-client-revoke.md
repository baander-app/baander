# app:oauth:client:revoke

Revoke an OAuth client together with every access and refresh token issued to it. Use it when a client is retired or compromised. The command does what `POST /api/admin/oauth/clients/{clientId}/revoke` does in the admin API.

## Quick start

```bash
make exec cmd="php bin/console app:oauth:client:revoke V1StGXR8_Z5jdHi6B-myT"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `client-id` | Yes | The client's `client_id`, as [app:oauth:client:list](app-oauth-client-list.md) shows it |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the revoked client, as the admin API's `data` payload, in JSON |

## Details

Revocation is immediate and cannot be undone. The client is marked revoked and its access and refresh tokens are revoked in the same transaction, so every user signed in through the client is signed out of it. Revoking the client alone would not be enough: the API checks each access token's own revocation flag, and the tokens would otherwise stay usable until they expire. Token issuance takes a shared lock on the client row, so revocation and issuance cannot interleave.

After revocation the client can no longer start a flow or get tokens; the authorization server answers it as an unknown client. It stays in the list with `Revoked` set to `yes`. To let the application in again, register a new client with [app:oauth:client:create](app-oauth-client-create.md).

With `--json` the command prints only the client as the API's `data` object, the same resource `POST /api/admin/oauth/clients/{clientId}/revoke` returns: `clientId`, `name`, `type`, `redirectUris`, `revoked`, `createdAt` and `updatedAt`.

Revoking a client that is already revoked succeeds again, so a retry is harmless. The first-party client and users' personal access clients cannot be revoked with this command. Users revoke their own personal access clients.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Client and its tokens revoked, or the client was already revoked |
| 1 | The client ID is malformed or unknown, the client is not administered, or another error occurred; the message says why |
