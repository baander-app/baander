# app:user:disable

Disable a user account. The user can no longer authenticate until re-enabled. Use this to revoke access for a compromised, departed, or problematic account without deleting it.

## Quick start

```bash
make exec cmd="php bin/console app:user:disable alice@example.com"
```

By UUID:

```bash
make exec cmd="php bin/console app:user:disable 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | User's email address or UUID |

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | User disabled successfully |
| 1 | Failure — the user was not found, or another error occurred (message is printed) |

## Tips

- Disabling is reversible — re-enable the account with `app:user:enable`.
- The identifier can be either the email or the UUID; the command resolves it on the backend.
- Active sessions for the user are affected according to the auth flow's token handling — verify behavior in your environment if immediate logout is required.
