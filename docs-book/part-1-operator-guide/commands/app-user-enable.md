# app:user:enable

Enable a previously disabled user account. The user can authenticate again. Use this to restore access after an account was disabled with `app:user:disable`.

## Quick start

```bash
make exec cmd="php bin/console app:user:enable alice@example.com"
```

By UUID:

```bash
make exec cmd="php bin/console app:user:enable 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | User's email address or UUID |

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | User enabled successfully |
| 1 | Failure — the user was not found, or another error occurred (message is printed) |

## Tips

- This is the inverse of `app:user:disable`.
- The identifier can be either the email or the UUID; the command resolves it on the backend.
- Enabling an account that was never disabled is a no-op that still reports success.
