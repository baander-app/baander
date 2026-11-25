# app:user:change-email

Change a user's email address. It does the same as changing the email in the admin panel (`PATCH /api/admin/users/{publicId}`): the new address takes effect at once and counts as verified. A user who changes their own address in **Settings** keeps the old one until they open the link Baander sends to the new one; an operator's change needs no link.

## Quick start

The Make examples below target the checkout's development preview. For an
installation, use the `dc` function from
[Installation](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec web php bin/console …`.

```bash
make exec cmd="php bin/console app:user:change-email alice@baander.app alice.new@baander.app"
```

By public ID:

```bash
make exec cmd="php bin/console app:user:change-email aB3dE5fG7hJ9kL1mN3pQr alice.new@baander.app"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | User's current email address or public ID |
| `email` | Yes | The new email address. Baander stores it in lower case. |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the changed user, as the admin API's `data` payload, in JSON |

## What it changes

- The account's email address, which it then signs in with. The new address counts as verified, so the user can add a passkey or turn on TOTP with it.
- A change the user asked for in **Settings** and has not confirmed yet is dropped, and its link stops working.
- Verification and password reset links sent to the old address stop working.
- Baander emails the old address that the account's address changed, without naming the new one. If sending fails, the command still succeeds and the failure is logged.
- Giving the current address changes nothing and sends no email.

Confirm that the new address belongs to the user before you run the command: it skips the verification link a self-service change needs.

With `--json` the command prints only the user as the API's `data` object, the same user resource `PATCH /api/admin/users/{publicId}` returns: `publicId`, `name`, `email`, `emailVerifiedAt`, `roles`, `disabled`, `createdAt` and `updatedAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Email address changed, or already the given address |
| 1 | The user was not found, another account uses the address, or another error occurred; the message says why |
| 2 | The new address is not a valid email address |
