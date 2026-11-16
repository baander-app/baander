# app:dev:create-users

Create the standard development users. This dev-only command seeds two fixed test accounts and is safe to run repeatedly: it skips an existing user instead of treating it as an error.

## Quick start

```bash
make exec cmd="php bin/console app:dev:create-users"
```

## Details

The command takes no arguments or options. It dispatches `CreateUserCommand` messages for the two built-in dev accounts:

| Email | Password | Roles |
|-------|----------|-------|
| `admin@baander.test` | `admin` | `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` |
| `user@baander.test` | `user` | `ROLE_USER` |

When a user already exists, the command reports `User exists` and continues. Any other `RuntimeException` while creating a user aborts the run with a failure. On success it prints a summary table of the accounts it manages.

`app:dev:setup` normally runs this command as an isolated subprocess, so the Messenger bus and the compiled container boot fresh.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | All dev users created or already present |
| 1 | A user failed to create for a reason other than "already exists" |

## Tips

- Never run this in production. The passwords (`admin`, `user`) are public and known.
- Running it again creates no duplicate users and does not fail on existing ones.
