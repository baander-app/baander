# app:dev:create-users

Create the standard development users. This is a dev-only command that seeds two fixed test accounts and is safe to run repeatedly — existing users are skipped, not treated as errors.

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

This command is normally invoked as an isolated subprocess by `app:dev:setup` so the Messenger bus and compiled container boot fresh.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | All dev users created or already present |
| 1 | A user failed to create for a reason other than "already exists" |

## Tips

- Never run this in production. The passwords (`admin`, `user`) are public and known.
- Re-running is idempotent — it will not duplicate users or error on existing ones.
