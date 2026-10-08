# app:user:create

Create a new user account. The command runs the same use case as creating a user in the admin panel (`POST /api/admin/users` in the admin API), so the account starts with a verified email address and the default notification preferences.

## Quick start

Create a regular user:

```bash
echo "securepassword" | make exec cmd="php bin/console app:user:create alice@baander.app Alice --password"
```

Create a super admin, for example to recover from a lockout:

```bash
echo "adminpassword" | make exec cmd="php bin/console app:user:create root@baander.app Root --password --role=super-admin"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `email` | Yes | User's email address |
| `name` | Yes | Display name |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--password` | — | Read password from stdin instead of prompting. Use this in scripts and CI. |
| `--role` | `user` | Role to assign: `user`, `admin` or `super-admin`. Repeat the option to assign several, as in `--role=user --role=admin` |

## Password input

When you run the command interactively (without `--password`), it prompts for a hidden password. In scripts or CI, pipe the password via stdin:

```bash
echo "mypassword123" | php bin/console app:user:create user@baander.app "John Doe" --password
```

The password must be between 8 and 255 characters.

## Roles

`user`, `admin` and `super-admin` assign `ROLE_USER`, `ROLE_ADMIN` and `ROLE_SUPER_ADMIN`. A super admin has the rights of an admin, and an admin those of a user. A role named twice is assigned once. To change the roles later, use [app:user:roles](app-user-roles.md).

The command acts with full authority. The admin panel lets an admin create only regular users, and only while the `admin.can_create_users` setting is on; shell access is bound by neither.

When the roles include `admin` or `super-admin`, the command asks for confirmation before it creates the user, unless it runs non-interactively:

```
 Create user with the roles ROLE_SUPER_ADMIN? (yes/no) [no]:
```

Answering no creates nothing.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | User created, or the operator declined the confirmation |
| 1 | Something went wrong, such as an invalid email address or role, a password outside the policy or an address already in use; the message says why |

## Tips

- Use `--password` whenever you're running from a script or cron job.
- After creating a user, they can log in immediately with their email and password.
