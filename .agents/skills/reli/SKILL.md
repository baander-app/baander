---
name: reli
description: Profile Baander PHP workers with the repository's reli-prof sidecar and inspect saved traces or memory snapshots.
---

# Profile Baander workers

Run the tracked `bin/reli` wrapper from the repository root. It uses Docker's
`baander-profiler` sidecar and targets `baander-app`, writing captures below
`.reli/`. Docker Compose defines the optional `profiling` profile, host PID
namespace, SYS_PTRACE capability, and repository mount at `/var/www/html`.
The host does not need a `reli` executable. Composer currently declares
reli-prof 0.12; the sidecar image is unpinned, so check its command help if the
wrapper and running image disagree.

```bash
bin/reli up
bin/reli ps
bin/reli flamegraph -p WORKER_PID -d 10
bin/reli memory -p WORKER_PID
bin/reli leak-detect -p WORKER_PID -d 60
bin/reli capture -p WORKER_PID -d 30
```

Choose an explicit worker PID from `ps`. These are Docker-host PIDs, not
necessarily the process numbers shown inside the application container.
`-P pattern` picks the first full-command match: `-P swoole` can select the
master or manager instead of the HTTP worker receiving the traffic. Messenger
consumers, task workers, and HTTP workers are different profiling targets.
Use bounded captures while reproducing the specific workload. A leak comparison
needs comparable workloads and the same surviving process across snapshots.

Captures contain a `manifest.json`; `.reli/latest` points to the last capture.
Trace/flamegraph captures use `.rbt`; memory captures use `.rmem` and text reports.
Inspect artifact existence, size, and content before reporting success: the
wrapper suppresses some converter/report/compare errors and prints success
even when those operations fail. Stopping the Docker exec client is not proof
that the in-container tracer has exited; check processes if a capture persists.

```bash
bin/reli ls
bin/reli report /absolute/repository/path/.reli/CAPTURE_DIRECTORY
bin/reli peek 'global::$container' -p WORKER_PID
```

Use absolute capture paths for `report` and `explore`: the wrapper's container
path conversion only replaces an absolute repository prefix. Reports need a
memory snapshot. `bin/reli explore` is an interactive TTY command and prefers a
trace when a capture contains both trace and memory. For heap exploration,
select a memory-only capture. `bin/reli top` is also interactive; prefer finite
captures for unattended investigations.

`bin/reli-mcp-bridge` can expose `rmem:mcp` over stdio when explicitly configured
in the active client. It needs the running profiler and an existing snapshot;
do not assume the MCP integration is available merely because the bridge exists.
The bridge searches `.reli/latest` and then older memory captures.

`make prof-up`, `prof-down`, `prof-top`, `prof-trace`, `prof-memory`, and
`prof-flamegraph` wrap these commands (`PID=...` for targeted captures).
Before stopping, inspect the installed Compose behavior: `bin/reli down` calls
`docker compose --profile profiling down profiler`; do not assume its scope is
sidecar-only. Capture cleanup (`clean`, `clean --all`) deletes local artifacts;
perform it when the user requested cleanup or those artifacts are disposable.
Saved snapshots can contain credentials and user data; keep them local unless
their disclosure is part of the requested work.
