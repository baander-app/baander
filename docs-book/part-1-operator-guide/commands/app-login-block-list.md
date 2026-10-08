# app:login-block:list

List the blocks the login honeypot recorded, newest first, one page at a time. The honeypot is a hidden field on the sign-in form; bots fill it in, and each such attempt is recorded as a block. The command shows what `GET /api/admin/login-blocks` returns in the admin API, which the login blocks page of the admin security area reads.

## Quick start

```bash
make exec cmd="php bin/console app:login-block:list"
```

The next page:

```bash
make exec cmd="php bin/console app:login-block:list --offset 50"
```

## Options

| Option | Description |
|--------|-------------|
| `--limit` | Blocks per page, from 1 to 100; defaults to 50 |
| `--offset` | Blocks to skip; defaults to 0 |
| `--json` | Print the blocks exactly as the API's `data` array, in JSON |

## Details

The default table has one row per block:

| Column | Content |
|--------|---------|
| IP address | The address the attempt came from |
| Email | The email address the attempt used, or `-` |
| Field value | What the bot wrote into the honeypot field |
| User agent | The attempt's `User-Agent` header |
| Blocked | When the attempt was recorded, in ISO 8601 format |
| ID | The block UUID, which [app:login-block:delete](app-login-block-delete.md) takes |

Below the table the command prints which blocks the page holds and the total, for example `Blocks 1-50 of 120.` When the page is empty, it prints `No login blocks are recorded.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The blocks could not be read; the message says why |
| 2 | `--limit` or `--offset` is not an integer or is out of range |
