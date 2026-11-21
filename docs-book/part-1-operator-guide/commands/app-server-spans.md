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

Run the command in the web container: it talks to the web server running there. Requests admitted by `diagnostics.spans_enabled` record one span each. All workers write to one buffer, which holds the last 500 spans, so the list covers requests handled by any worker. The buffer remains allocated when capture is off so capture can be enabled without restarting the server.

The command prints one row per span:

| Column | Content |
|--------|---------|
| Started (UTC) | When the request started |
| Operation | The method and route name, such as `GET api_album_index`, or the method alone when no route matched |
| Status | The HTTP status code of the response |
| Duration (ms) | Time from the start of the request to the end of its response |
| Trace ID | The span's trace ID |

A span records the method, route name, status and duration only. It never holds the URL, query string or headers, so tokens and signed-URL signatures do not reach the buffer. When the buffer is empty, the command prints `No spans recorded.`

### Inspecting spans in the admin panel

Open **Admin → Diagnostics → Recent spans** to search the retained buffer. Search matches operation names, services, trace and span IDs, and attribute names or values. Combine it with service, error and duration filters, or sort by the slowest requests. Duration filters use milliseconds; an error means a telemetry error or an HTTP response of 400 or higher.

Select a result to inspect its IDs, timing, status and attributes. The trace waterfall shows retained spans with the same trace ID; select a row to inspect that span. Source locations appear when a span supplies them. The current HTTP capture produces one independent root span per request, so ordinary requests have a one-span waterfall.

Search covers at most 500 completed spans from this server instance. New traffic overwrites older spans, and restarting the server loses the buffer. Missing parents are identified in the waterfall; a retained trace may be incomplete. This view does not provide historical search or reconstruct unrecorded child spans, events or logs.

The page samples spans every ten seconds while it is active. **Refresh** requests a new snapshot immediately. A selected span keeps its identity across refreshes; the inspector reports when it is no longer retained. **Clear** empties the shared buffer and dismisses the selected details after the request succeeds. Diagnostic requests can themselves produce new spans, so clearing need not leave the list empty.

### Enabling capture

`diagnostics.spans_enabled` defaults to `true` only with `APP_ENV=dev`. It defaults to `false` in staging, production and test environments. A super administrator can change **Admin → Settings → Diagnostics → HTTP request spans**, or use the settings commands:

```bash
make exec cmd="php bin/console app:settings:set diagnostics.spans_enabled true"
make exec cmd="php bin/console app:settings:set diagnostics.spans_enabled false"
make exec cmd="php bin/console app:settings:reset diagnostics.spans_enabled"
```

Each web worker shares one capture decision among its request coroutines and caches it for five seconds. After the cache expires, the next request refreshes it. A request arriving during that lookup skips capture. If the lookup fails, capture stays off until a request retries after five seconds. No restart is needed.

Disabling capture lets spans already started finish. Retained spans remain available until cleared or overwritten by newer spans. Captured spans reach the buffer whether or not `OTEL_ENABLED` is set. Export to the OTLP endpoint in `OTEL_EXPORTER_OTLP_ENDPOINT` requires both capture to be enabled and `OTEL_ENABLED=true`.

With `--clear`, the command asks before it empties the buffer on a terminal. Without a terminal and without `--force` it changes nothing and exits with code 2. Clearing empties the buffer for every worker.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Spans listed, possibly none, or the buffer was emptied |
| 1 | No web server is running, the server could not answer, or the operator declined to clear; the message says why |
| 2 | `--limit` is not a whole number, or `--clear` ran without a terminal and without `--force`; nothing was changed |
