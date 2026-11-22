# app:user:disable

Disable a user account and end its sessions. The user cannot sign in until the account is enabled again. Use this to revoke access for a compromised, departed or problematic account without deleting it. The command runs the same use case as disabling a user in the admin panel (`POST /api/admin/users/{publicId}/disable` in the admin API).

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

```bash
make exec cmd="php bin/console app:user:disable alice@baander.app"
```

By public ID:

```bash
make exec cmd="php bin/console app:user:disable aB3dE5fG7hJ9kL1mN3pQr"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | User's email address or public ID |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the changed user, as the admin API's `data` payload, in JSON |

## Details

Disabling revokes every access and refresh token the user has, in the same transaction that saves the disabled account. From then on, the API refuses the user's tokens, a refresh fails with `invalid_grant`, and a new WebSocket handshake is refused.

After the disable is saved, the web server closes the user's open WebSocket connections on every worker, with close code 1008. A new connection needs a valid access token, and the account has none. The command reaches the server through its control socket, so run it in the web container, as `make exec` does. In a container without a web server, the command still disables the account and revokes its tokens but cannot reach the open connections, and it logs a warning that says so; the web server's socket reconciliation closes them within a minute. If the web server cannot close them, the command fails with a message that says so; the account stays disabled, and the reconciliation closes the connections on its next run. Run the command again to retry the close at once.

Two kinds of media access do not end at once:

- A media response that is already being sent finishes. A track file, or a transcode that is still encoding, keeps streaming until that response ends. The next request is refused, including a byte-range request for the same track.
- Signed video stream URLs (HLS and DASH) are not tied to the user's tokens, so revoking the tokens does not end them. Every manifest, segment and subtitle request checks that the owner of the transcode job it names may still play the video, so once the account is disabled, the next request on one of the user's URLs answers `409`; the player keeps only what it has buffered. The user cannot sign new stream URLs once the account is disabled. See [Disabled accounts](../administration/sessions-and-access.md#disabled-accounts).

Disabling an account that is already disabled leaves the account unchanged and closes any WebSocket connection of the user that is still open.

The command does not disable the only enabled super admin: the install keeps one, and the account stays enabled. The admin API refuses that disable the same way, with `409` and `details.reason` `last_super_admin`. To disable the account, first make another user a super admin with [app:user:roles](app-user-roles.md). A disabled super admin does not count, and an install with no enabled super admin refuses nothing. Disables, deletions and demotions take turns, so two of them running at the same moment cannot remove the last two super admins between them.

With `--json` the command prints only the user as the API's `data` object, the same user resource `POST /api/admin/users/{publicId}/disable` returns: `publicId`, `name`, `email`, `emailVerifiedAt`, `roles`, `disabled`, `createdAt` and `updatedAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The account is disabled |
| 1 | The user was not found, the user is the only enabled super admin, the web server did not close the user's open WebSocket connections (the account is disabled all the same), or another error occurred; the message says why |

## Tips

- Disabling is reversible: enable the account with [app:user:enable](app-user-enable.md). Enabling restores none of the revoked tokens, so the user signs in again.
- The identifier can be the email address or the public ID.
