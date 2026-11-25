# app:user:roles

Replace a user's roles with the given set. The command runs the same use case as assigning roles in the admin panel (`POST /api/admin/users/{publicId}/roles` in the admin API).

## Quick start

The Make examples below target the checkout's development preview. For an
installation, use the `dc` function from
[Installation](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec web php bin/console …`.

Make a user an admin:

```bash
make exec cmd="php bin/console app:user:roles alice@baander.app ROLE_USER ROLE_ADMIN"
```

Take the admin role away again:

```bash
make exec cmd="php bin/console app:user:roles alice@baander.app ROLE_USER"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | The user's email address or public ID |
| `roles` | Yes | The complete new set of roles, separated by spaces: `ROLE_USER`, `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the changed user, as the admin API's `data` payload, in JSON |

## Details

The roles replace the user's current ones; any role not named is removed. A role named twice counts once. Setting the roles the user already has, in any order, succeeds and changes nothing.

`ROLE_SUPER_ADMIN` includes the rights of `ROLE_ADMIN`, and `ROLE_ADMIN` those of `ROLE_USER`. An unknown role is rejected and the user's roles stay as they were.

The command acts with full authority and can change any user's roles, a super admin's included, except that it does not demote the only enabled super admin: a set of roles without `ROLE_SUPER_ADMIN` for that user is refused, and their roles stay as they were. The admin API refuses it the same way, with `409` and `details.reason` `last_super_admin`. Make another user a super admin first. A disabled super admin does not count, and an install with no enabled super admin refuses nothing. Demotions, deletions and disables take turns, so two super admins who demote each other at the same moment leave one of them a super admin.

With `--json` the command prints only the user as the API's `data` object, the same user resource `POST /api/admin/users/{publicId}/roles` returns: `publicId`, `name`, `email`, `emailVerifiedAt`, `roles`, `disabled`, `createdAt` and `updatedAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Roles replaced, or the user already had them |
| 1 | No user has the email address or public ID, the roles would demote the only enabled super admin, or another error occurred; the message says why |
| 2 | A role is unknown |
