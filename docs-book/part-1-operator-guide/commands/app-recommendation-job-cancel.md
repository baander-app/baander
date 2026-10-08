# app:recommendation:job:cancel

Cancel a pending or running recommendation job. It is the shell counterpart of **Cancel** on the admin Recommendations page, `DELETE /api/admin/recommendations/jobs/{publicId}`, and applies the same rules.

## Quick start

```bash
make exec cmd="php bin/console app:recommendation:job:cancel V1StGXR8_Z5jdHi6B-myT"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `publicId` | The job's public ID, as `app:recommendation:job:list` shows it |

## Details

The command marks the job `cancelled`. A pending job then never starts. A running job stops at its next check: a job on the web server's CPU process pool checks while it saves recommendations, and a job run by `app:recommendation:generate` or `app:recommendation:job:requeue` checks before each strategy. Recommendations the job saved before it stopped are kept.

Cancelling a cancelled job succeeds and changes nothing. A completed or failed job cannot be cancelled; the command reports the conflict, as the API answers 409. To run a cancelled job again, use `app:recommendation:job:requeue`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The job is cancelled |
| 1 | No job has this public ID, or the job already completed or failed |
