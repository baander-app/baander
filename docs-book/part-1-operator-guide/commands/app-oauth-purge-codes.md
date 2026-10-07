# app:oauth:purge-codes

Delete OAuth authorization codes and device codes that expired more than an hour ago. A scheduled job runs the same purge every day; use this command to run it now.

## Quick start

```bash
make exec cmd="php bin/console app:oauth:purge-codes"
```

The command takes no arguments or options.

## Details

An authorization code or device code is deleted once its expiry is more than one hour in the past, whether it was used, denied or never touched. The hour keeps an expired device code long enough for a device that is still polling to learn that its code expired. A code without an expiry is never deleted; Baander always sets one.

The output counts the deleted codes and gives the cutoff in UTC:

```text
[OK] Deleted 3 authorization code(s) and 12 device code(s) that expired before 2026-10-07 02:30:00 UTC.
```

The scheduled job **Purge expired OAuth codes** runs the same purge daily at 03:30 UTC. It is created on install, and you can reschedule or pause it in the scheduler admin.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Purge completed; the counts are printed |
| 1 | The purge failed (message is printed) |

## Tips

- Safe to run at any time. A code that can still be redeemed is never deleted.
- The daily job is enough for normal use. Run the command by hand only to clear a backlog, for example after the job was paused.
