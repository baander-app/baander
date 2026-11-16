# app:user:enable

Enable a user account that was disabled with [app:user:disable](app-user-disable.md), so the user can sign in again. The command runs the same use case as enabling a user in the admin panel (`POST /api/admin/users/{publicId}/enable` in the admin API).

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

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
| 0 | User enabled, or the account was not disabled |
| 1 | The user was not found, or another error occurred; the message says why |

## Tips

- The identifier can be the email address or the public ID.
- Enabling an account that is not disabled changes nothing and still succeeds.
- Enabling restores none of the tokens the disable revoked, so the user signs in again.
