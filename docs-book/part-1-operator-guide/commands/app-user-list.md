# app:user:list

List user accounts, newest first, one page at a time. The command shows what the admin panel's user list shows (`GET /api/admin/users` in the admin API), with the same filters and paging.

## Quick start

```bash
make exec cmd="php bin/console app:user:list --role=ROLE_ADMIN --disabled"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--role` | — | Only users holding this role: `ROLE_USER`, `ROLE_ADMIN` or `ROLE_SUPER_ADMIN` |
| `--disabled` | — | Only disabled users. `--disabled=false` lists only enabled users. Without the option, both are listed |
| `--limit` | `50` | Users per page, 1 to 100 |
| `--offset` | `0` | Users to skip, for the next page |
| `--json` | — | Print the users as the admin API's `data` array, in JSON, and nothing else |

## Details

The command prints a table with one row per user:

| Column | Content |
|--------|---------|
| Email | The address the user signs in with |
| Name | The display name |
| Roles | The assigned roles, comma-separated |
| Disabled | `yes` or `no` |
| UUID | The identifier the other `app:user:*` commands and the admin API accept |
| Created | Creation time in ISO 8601 format |

Below the table, a line such as `Users 1-50 of 120.` gives the position of the page. Run the command again with `--offset=50` for the next one. When no user matches, the command prints `No users match.`

The role filter matches assigned roles only. A super admin listed with `ROLE_SUPER_ADMIN` alone does not appear under `--role=ROLE_ADMIN`, although the role hierarchy grants it admin rights.

The command reads every account. The admin panel lets an admin list users only while the `admin.can_view_users` setting is on; shell access is not bound by it.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The users could not be read; the message says why |
| 2 | An unknown role, a `--disabled` value other than true or false, or a limit or offset that is not a whole number in range |
