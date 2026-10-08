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

## Details

Disabling revokes every access and refresh token the user has, in the same transaction that saves the disabled account. From then on, the API refuses the user's tokens, a refresh fails with `invalid_grant`, and a new WebSocket handshake is refused.

Two things are not cut off at once: a WebSocket connection that is already open ends when it closes, and a stream URL that was already signed works until it expires.

Disabling an account that is already disabled succeeds without change.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The account is disabled |
| 1 | The user was not found, or another error occurred; the message says why |

## Tips

- Disabling is reversible: enable the account with [app:user:enable](app-user-enable.md). Enabling restores none of the revoked tokens, so the user signs in again.
- The identifier can be the email address or the UUID.
