# app:user:mark-email-verified

Mark a user's current email address verified, as opening the verification link would. Use it on an install that sends no email, or when a user cannot receive the link. A user needs a verified address to add a passkey or turn on TOTP. The command runs the same use case as the admin API's `POST /api/admin/users/{publicId}/verify-email`.

## Quick start

The Make examples below target the checkout's development preview. For an
installation, use the `dc` function from
[Installation](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec web php bin/console …`.

```bash
make exec cmd="php bin/console app:user:mark-email-verified alice@baander.app"
```

By public ID:

```bash
make exec cmd="php bin/console app:user:mark-email-verified aB3dE5fG7hJ9kL1mN3pQr"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | User's email address or public ID |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the user, as the admin API's `data` payload, in JSON |

## Details

The command verifies the account's current address only. An email change the user asked for in **Settings** stays pending until they open the link sent to the new address; to move the account to another address at once, use [app:user:change-email](app-user-change-email.md).

Confirm that the address belongs to the user before you run the command. An account whose address was never verified may have been registered by someone else under it, and a password reset to that address would otherwise let the address's owner claim it.

Running the command for a verified address changes nothing and succeeds.

With `--json` the command prints only the user as the API's `data` object, the same user resource `POST /api/admin/users/{publicId}/verify-email` returns: `publicId`, `name`, `email`, `emailVerifiedAt`, `roles`, `disabled`, `createdAt` and `updatedAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The address is verified, or already was |
| 1 | The user was not found, or another error occurred; the message says why |
