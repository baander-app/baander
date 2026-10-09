# app:user:change-email

Change a user's email address. It does the same as changing the email in the admin panel (`PATCH /api/admin/users/{id}`) and as users changing their own address in **Settings**: the new address starts unverified and Baander emails it a verification link.

## Quick start

```bash
make exec cmd="php bin/console app:user:change-email alice@baander.app alice.new@baander.app"
```

By UUID:

```bash
make exec cmd="php bin/console app:user:change-email 0192a3b4-c5d6-7890-abcd-ef1234567890 alice.new@baander.app"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | User's current email address or UUID |
| `email` | Yes | The new email address. Baander stores it in lower case. |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the changed user, as the admin API's `data` payload, in JSON |

## What it changes

- The account's email address, which it then signs in with.
- The address is marked unverified. Until the user opens the verification link, Baander sends them no notification email.
- Verification and password reset links sent to the old address stop working.
- A verification link goes to the new address at once. If sending fails, the command still succeeds and the failure is logged; the user can ask for a new link from **Settings**.
- Giving the current address changes nothing and sends no email.

With `--json` the command prints only the user as the API's `data` object, the same user resource `PATCH /api/admin/users/{id}` returns: `id`, `publicId`, `name`, `email`, `emailVerifiedAt`, `roles`, `disabled`, `createdAt` and `updatedAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Email address changed, or already the given address |
| 1 | The user was not found, another account uses the address, or another error occurred; the message says why |
| 2 | The new address is not a valid email address |
