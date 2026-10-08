# app:recommendation:job:requeue

Run a failed or cancelled recommendation job again as a new job. It is the shell counterpart of **Requeue** on the admin Recommendations page, `POST /api/admin/recommendations/jobs/{publicId}/requeue`, and applies the same rules.

## Quick start

```bash
make exec cmd="php bin/console app:recommendation:job:requeue V1StGXR8_Z5jdHi6B-myT"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `publicId` | The public ID of the failed or cancelled job, as `app:recommendation:job:list` shows it |

## Details

The command creates a new job with the original's mode, user and metadata, linked to the original. The metadata adds `requeued_from`, `requeued_at`, `requeued_by: cli` and `requeued_reason`, which is the original's fail reason or `manual_requeue`. The new job then runs in the command's own process, as with `app:recommendation:generate`, and the run is recorded in the job monitor as `RequeueRecommendationJobCommand`. Requeueing from the web starts the new job on the web server's CPU process pool instead.

When the run finishes, the command prints the new job's public ID, the job monitor ID, the mode, the final status and the recommendations each strategy saved.

Only a failed or cancelled job can be requeued. For any other job the command reports the conflict, as the API answers 409, and creates nothing.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The new job completed |
| 1 | No job has this public ID, the job has not failed and was not cancelled, the new job was cancelled before it finished, or generation failed |
