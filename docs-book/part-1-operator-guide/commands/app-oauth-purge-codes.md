# app:oauth:purge-codes

Delete OAuth authorization codes, device codes and pushed authorization requests that expired more than an hour ago. A scheduled job runs the same purge every day; use this command to run it now.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

```bash
make exec cmd="php bin/console app:oauth:purge-codes"
```

The command takes no arguments or options.

## Details

An authorization code, device code or pushed authorization request is deleted once its expiry is more than one hour in the past, whether it was used, denied or never touched. The hour keeps an expired device code long enough for a device that is still polling to learn that its code expired. A code without an expiry is never deleted; Baander always sets one. Pushed authorization requests always expire, after five minutes by default (`auth.par.request_uri_ttl`).

The output counts the deleted codes and requests and gives the cutoff in UTC:

```text
[OK] Deleted 3 authorization code(s), 12 device code(s) and 5 pushed authorization request(s) that expired before 2026-10-07 02:30:00 UTC.
```

The scheduled job **Purge expired OAuth codes** runs the same purge daily at 03:30 UTC. It is created on install, and you can reschedule or pause it in the scheduler admin.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Purge completed; the counts are printed |
| 1 | The purge failed (message is printed) |

## Tips

- Safe to run at any time. A code that can still be redeemed, or a pushed request that can still be decided, is never deleted.
- The daily job is enough for normal use. Run the command by hand only to clear a backlog, for example after the job was paused.
