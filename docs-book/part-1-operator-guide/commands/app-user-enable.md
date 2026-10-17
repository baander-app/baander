# app:user:enable

Enable a previously disabled user account. The user can authenticate again. Use this to restore access after an account was disabled with `app:user:disable`. The command runs the same use case as enabling a user in the admin panel (`POST /api/admin/users/{publicId}/enable` in the admin API).

## Quick start

```bash
make exec cmd="php bin/console app:user:enable alice@baander.app"
```

By public ID:

```bash
make exec cmd="php bin/console app:user:enable aB3dE5fG7hJ9kL1mN3pQr"
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

With `--json` the command prints only the user as the API's `data` object, the same user resource `POST /api/admin/users/{publicId}/enable` returns: `publicId`, `name`, `email`, `emailVerifiedAt`, `roles`, `disabled`, `createdAt` and `updatedAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | User enabled successfully |
| 1 | The user was not found, or another error occurred; the message says why |

## Tips

- This is the inverse of `app:user:disable`.
- The identifier can be either the email or the public ID; the command resolves it on the backend.
- Enabling an account that was never disabled is a no-op that still reports success.
