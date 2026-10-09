# app:recommendation:generate

Generate music recommendations with every strategy, as a recommendation job that runs in the command's own process. It is the shell counterpart of **Generate** on the admin Recommendations page, `POST /api/admin/recommendations/generate`, and leaves the same job record, which `app:recommendation:job:list` and the admin page list.

## Quick start

Replace the recommendations of every song:

```bash
make exec cmd="php bin/console app:recommendation:generate"
```

Replace only those of songs updated in the last seven days:

```bash
make exec cmd="php bin/console app:recommendation:generate --mode incremental"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:recommendation:generate --mode=incremental --json"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--mode` (`-m`) | `full` | `full` replaces the recommendations of every song; `incremental` only those of songs updated in the last seven days |
| `--user-id` (`-u`) | none | Store the collaborative recommendations for this user (UUID) instead of for everyone. Content and genre recommendations are shared and stay unscoped |
| `--json` | off | Print only the result, as the admin API's `data` payload, in JSON |

## Details

The command creates a recommendation job, then runs it in its own process: in the web server, jobs run on the CPU process pool, which a console process cannot reach. The job record records `triggered_by: cli`. The job moves to `in_progress`, names each strategy (collaborative, content, genre) as it starts, and ends `completed`. Follow it from another shell with `app:recommendation:job:list` or `app:recommendation:job:show`.

The run is also recorded in the job monitor as `GenerateRecommendationsCommand`, so `app:monitor:jobs` and the admin Job monitor page show it.

When the run finishes, the command prints the recommendation job's public ID, the job monitor ID, the mode, the final status and the number of recommendations each strategy saved.

Cancelling the job with `app:recommendation:job:cancel` or from the admin page stops the run before its next strategy starts. The strategies that already ran keep their recommendations, and the command exits with 1. A cancellation that arrives as the last strategy finishes is kept too: the job stays `cancelled` rather than `completed`. If the job completes first, the cancellation is refused. If generation throws, the job is marked `failed` with the error as its reason, unless it was cancelled meanwhile, and the command prints the error and exits with 1.

The command always generates, whatever the `recommendations.auto_generate` [server setting](../configuration.md#server-settings) says. That setting governs only the daily **Generate recommendations** scheduled job; see [Scheduled recommendations](../configuration.md#scheduled-recommendations).

A full run loads every song and every user's listening history, so on a large library it can take long and use a lot of memory.

With `--json` the command prints only the API's `data` object: `job_id`, `public_id`, `mode`, `status`, `execution` (always `sync`, because the job runs in this process) and `counts`, the recommendations each strategy saved. A job that was cancelled or stopped before it finished still prints the object and exits with 1. The job-monitor ID that the table shows is not part of the JSON.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The job completed |
| 1 | The job was cancelled before it finished, or generation failed |
| 2 | Unknown `--mode` or a `--user-id` that is not a UUID; no recommendation job was created |
