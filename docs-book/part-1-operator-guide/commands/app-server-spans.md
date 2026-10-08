# app:server:spans

List the recent request spans of the running web server, or empty the span buffer with `--clear`. Listing does what `GET /api/debug/spans` does in the admin API, and `--clear` what `DELETE /api/debug/spans` does; the admin Server Diagnostics page shows the same spans under Recent spans. Both read the server through its control socket.

## Quick start

```bash
make exec cmd="php bin/console app:server:spans --limit=20"
```

To empty the buffer without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:server:spans --clear --force"
```

## Options

| Option | Description |
|--------|-------------|
| `--limit=N` | Number of spans to list, newest first; default 100. More than 500 lists 500 |
| `--clear` | Empty the span buffer instead of listing it |
| `--force` | With `--clear`, empty the buffer without asking; required when no terminal is attached |
| `--json` | Print the data as the admin API returns it, in JSON |

## Details

Run the command in the web container: it talks to the web server running there. Every HTTP request the server handles records one span. All workers write to one buffer, which holds the last 500 spans, so the list covers requests handled by any worker.

The command prints one row per span:

| Column | Content |
|--------|---------|
| Started (UTC) | When the request started |
| Operation | The method and route name, such as `GET api_album_index`, or the method alone when no route matched |
| Status | The HTTP status code of the response |
| Duration (ms) | Time from the start of the request to the end of its response |
| Trace ID | The span's trace ID |

A span records the method, route name, status and duration only. It never holds the URL, query string or headers, so tokens and signed-URL signatures do not reach the buffer. When the buffer is empty, the command prints `No spans recorded.`

Spans reach the buffer whether or not `OTEL_ENABLED` is set. `OTEL_ENABLED=true` additionally exports them to the OTLP endpoint in `OTEL_EXPORTER_OTLP_ENDPOINT`.

With `--clear`, the command asks before it empties the buffer on a terminal. Without a terminal and without `--force` it changes nothing and exits with code 2. Clearing empties the buffer for every worker.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Spans listed, possibly none, or the buffer was emptied |
| 1 | No web server is running, the server could not answer, or the operator declined to clear; the message says why |
| 2 | `--limit` is not a whole number, or `--clear` ran without a terminal and without `--force`; nothing was changed |
