# app:oauth:client:rotate-secret

Give a confidential OAuth client a new secret. Use it when a secret was lost or may have leaked. The command does what `POST /api/admin/oauth/clients/{clientId}/rotate-secret` does in the admin API.

## Quick start

```bash
make exec cmd="php bin/console app:oauth:client:rotate-secret V1StGXR8_Z5jdHi6B-myT"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `client-id` | Yes | The client's `client_id`, as [app:oauth:client:list](app-oauth-client-list.md) shows it |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the client and its new secret, as the admin API's `data` payload, in JSON |

## Details

The new secret replaces the old one at once: from then on the old secret no longer authenticates a token request. Access and refresh tokens the client already holds stay valid, so its signed-in users are not signed out. Install the new secret in the client before its next token request.

The command prints the client and its new secret, followed by a warning to store the secret now. Baander keeps only a SHA-256 digest and cannot show the secret again.

With `--json` the command prints only the client as the API's `data` object, the same resource `POST /api/admin/oauth/clients/{clientId}/rotate-secret` returns: `clientId`, `name`, `type`, `redirectUris`, `revoked`, `createdAt`, `updatedAt` and the new `clientSecret`. The JSON is the only copy of the secret, so store it before the output is discarded.

Only confidential clients have a secret. The command refuses:

- device and public clients, which have no secret;
- revoked clients;
- the first-party client and personal access clients, which administrators do not manage.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | New secret issued and printed |
| 1 | The client ID is malformed or unknown, the client has no secret, is revoked or is not administered, or another error occurred; the message says why |

## Tips

- To cut a client off entirely, including its issued tokens, use [app:oauth:client:revoke](app-oauth-client-revoke.md) instead.
