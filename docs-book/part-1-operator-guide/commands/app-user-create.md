# app:user:create

Create a new user account. Users created this way are immediately verified (no email confirmation needed) and receive a default notification preferences setup.

## Quick start

Create a regular user:

```bash
echo "securepassword" | make exec cmd="php bin/console app:user:create alice@example.com Alice --password --role user"
```

Create an admin (you'll be asked to confirm):

```bash
echo "adminpassword" | make exec cmd="php bin/console app:user:create admin@example.com Admin --password --role admin"
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
| `--role` | `user` | User role: `user` or `admin` |

## Password input

When you run the command interactively (without `--password`), it prompts for a hidden password. In scripts or CI, pipe the password via stdin:

```bash
echo "mypassword123" | php bin/console app:user:create user@example.com "John Doe" --password
```

The password must be at least 8 characters.

## Admin creation

When you create a user with `--role admin`, the command asks for confirmation before proceeding (unless running non-interactively):

```
 Create user with admin privileges? (yes/no) [no]:
```

Press `y` to confirm or `n` to cancel. In automated setups, the prompt is skipped.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | User created successfully |
| 1 | Something went wrong — check the error message |

## Tips

- Use `--password` whenever you're running from a script or cron job.
- Creating a user that already exists returns an error.
- After creating a user, they can log in immediately via the API with their email and password.
