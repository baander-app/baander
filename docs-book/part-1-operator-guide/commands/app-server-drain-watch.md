# app:server:drain:watch

The web server's drain watchdog. `app:serve` starts it when the server begins to stop and ends it once the server has stopped. You do not run it yourself: this page explains the extra process you may see during a stop, and the message it prints when a stop takes too long.

## Quick start

Running it by hand kills the given processes once the deadline has passed, so only read its options:

```bash
make exec cmd="php bin/console app:server:drain:watch --help"
```

## Options

| Option | Description |
|--------|-------------|
| `--deadline-ns` | The drain deadline, as an `hrtime(true)` instant in nanoseconds. Required |
| `--pid` | A process to kill at the deadline; repeatable. Ids 0 and 1 are refused |
| `--pgid` | A process group to kill at the deadline; repeatable. Groups 0 and 1 are refused |

## Details

Every stop of the web server (SIGTERM, SIGINT, `swoole:server:stop` or `DELETE /api/server` on the API server) must end within the drain deadline `WEB_DRAIN_SECONDS`, 30 seconds by default, and the watchdog enforces that deadline. When it passes before the server has stopped, the watchdog kills the server's manager process and the CPU pool's workers with SIGKILL, prints

```text
The web server did not stop by its drain deadline: killed processes [...] and process groups [...].
```

on stderr and exits with code 3. Killing the manager lets the server finish its stop, and the server then exits with status 1. The watchdog leaves a process alone when its id has been reused by another process since the watchdog started.

When the server stops in time, it sends the watchdog SIGTERM. On SIGTERM or SIGINT the watchdog exits with code 0 and kills nothing.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Ended before the deadline; nothing was killed |
| 2 | An option was missing or invalid |
| 3 | The deadline passed and the processes were killed |
