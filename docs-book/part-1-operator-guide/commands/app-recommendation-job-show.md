# app:recommendation:job:show

Show one recommendation job: its status, progress, the recommendations each strategy saved and its metadata. It is the shell counterpart of `GET /api/admin/recommendations/jobs/{publicId}`, which the admin Recommendations page polls while a job runs.

## Quick start

```bash
make exec cmd="php bin/console app:recommendation:job:show V1StGXR8_Z5jdHi6B-myT"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `publicId` | The job's public ID, as `app:recommendation:job:list` shows it |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the API's `data` object instead of the summary |

## Details

The summary lists the status, the mode, the progress, the current strategy, the creation, start and completion times, the fail reason and, for a requeued job, the ID of the job it repeats. A table of recommendations saved per strategy follows once the job has counts, then the job's metadata as JSON: who triggered it and when and, for a requeued job, which job it repeats, who requeued it and why.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job shown |
| 1 | No job has this public ID |
