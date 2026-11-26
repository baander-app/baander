# app:oauth:client:purge-revoked

Delete revoked personal access clients together with the tokens issued to them. A scheduled job runs the same purge every day; use this command to run it now.

## Quick start

The Make examples below target the checkout's development preview. For an
installation, use the `dc` function from
[Installation](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec web php bin/console …`.

```bash
make exec cmd="php bin/console app:oauth:client:purge-revoked"
```

The command takes no arguments or options.

## Details

A user revokes a personal access client from their account. That revokes its access and refresh tokens at once and removes the client from the user's list, but the client's row and its revoked tokens stay in the database. The purge deletes every revoked personal access client together with its access and refresh tokens, authorization codes, device codes and pushed authorization requests. A token presented after the purge is refused, as it was after revocation.

Other clients stay, revoked or not: administrators see revoked device, public and confidential clients in [app:oauth:client:list](app-oauth-client-list.md).

The output counts the deleted clients:

```text
[OK] Deleted 2 revoked personal access client(s).
```

The scheduled job **Purge revoked personal access clients** runs the same purge daily at 03:45 UTC. It is created on install or upgrade, and you can reschedule or pause it in the scheduler admin.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Purge completed; the count is printed |
| 1 | The purge failed (message is printed) |

## Tips

- Safe to run at any time. A client that is not revoked is never deleted, and a revoked one cannot be used again.
- The daily job is enough for normal use. Run the command by hand only to clear a backlog, for example after the job was paused.
