# app:user:disable

Disable a user account and end its sessions. The user cannot sign in until the account is enabled again. Use this to revoke access for a compromised, departed or problematic account without deleting it. The command runs the same use case as disabling a user in the admin panel (`POST /api/admin/users/{id}/disable` in the admin API).

## Quick start

```bash
make exec cmd="php bin/console app:user:disable alice@baander.app"
```

By UUID:

```bash
make exec cmd="php bin/console app:user:disable 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | User's email address or UUID |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the changed user, as the admin API's `data` payload, in JSON |

## Details

Disabling revokes every access and refresh token the user has, in the same transaction that saves the disabled account. From then on, the API refuses the user's tokens, a refresh fails with `invalid_grant`, and a new WebSocket handshake is refused.

After the disable is saved, the web server closes the user's open WebSocket connections on every worker, with close code 1008. A new connection needs a valid access token, and the account has none. The command reaches the server through its control socket, so run it in the web container, as `make exec` does. In a container without a web server, the command still disables the account and revokes its tokens but cannot reach the open connections, and it logs a warning that says so. If the web server cannot close them, the command fails with a message that says so; the account stays disabled. Run the command again to retry the close.

Two kinds of media access do not end at once:

- A media response that is already being sent finishes. A track file, or a transcode that is still encoding, keeps streaming until that response ends. The next request is refused, including a byte-range request for the same track.
- Signed video stream URLs (HLS and DASH) are not tied to a user, so disabling the account does not revoke them. A manifest URL works for the lifetime requested when it was signed, at most 24 hours. Each manifest signs the URLs it lists for another 24 hours when the player fetches it. A player that keeps fetching can therefore stream that video for up to 72 hours after the URL was signed with HLS (master playlist, media playlist, segments), and up to 48 hours with DASH (manifest, segments). The user cannot sign new stream URLs once the account is disabled. To end the signed URLs sooner, see [Disabled accounts](../security.md#disabled-accounts).

Disabling an account that is already disabled leaves the account unchanged and closes any WebSocket connection of the user that is still open.

With `--json` the command prints only the user as the API's `data` object, the same user resource `POST /api/admin/users/{id}/disable` returns: `id`, `publicId`, `name`, `email`, `emailVerifiedAt`, `roles`, `disabled`, `createdAt` and `updatedAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The account is disabled |
| 1 | The user was not found, the web server did not close the user's open WebSocket connections (the account is disabled all the same), or another error occurred; the message says why |

## Tips

- Disabling is reversible: enable the account with [app:user:enable](app-user-enable.md). Enabling restores none of the revoked tokens, so the user signs in again.
- The identifier can be the email address or the UUID.
