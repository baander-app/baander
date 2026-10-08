# app:user:roles

Replace a user's roles with the given set. The command runs the same use case as assigning roles in the admin panel (`POST /api/admin/users/{id}/roles` in the admin API).

## Quick start

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
| `identifier` | Yes | The user's email address or UUID |
| `roles` | Yes | The complete new set of roles, separated by spaces: `ROLE_USER`, `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` |

## Details

The roles replace the user's current ones; any role not named is removed. A role named twice counts once. Setting the roles the user already has, in any order, succeeds and changes nothing.

`ROLE_SUPER_ADMIN` includes the rights of `ROLE_ADMIN`, and `ROLE_ADMIN` those of `ROLE_USER`. An unknown role is rejected and the user's roles stay as they were.

The command acts with full authority and can change any user's roles, a super admin's included.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Roles replaced, or the user already had them |
| 1 | No user has the email address or UUID, or another error occurred; the message says why |
| 2 | A role is unknown |
