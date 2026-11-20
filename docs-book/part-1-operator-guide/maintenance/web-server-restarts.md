# Web server stops and restarts

Use this reference to set the container stop timeout and understand what listeners see when web restarts. It explains web draining; the independent worker lifecycle remains in [Background work](../workers/background-work.md).

## Stopping and Restarting the Web Server

An update that replaces the app container stops the web server in it, and so do `dc stop`, `dc restart`, `docker stop` and `docker compose restart app`. Docker sends `SIGTERM` to `app:serve`, and the server drains: it finishes what it can, tells clients to come back, and exits on its own. `SIGINT`, `swoole:server:stop` and `DELETE /api/server` on the internal API server start the same drain. A second stop request during a drain changes nothing, and a worker reload (`PATCH /api/server`) never starts one.

### What the drain does

The drain ends within the drain deadline `WEB_DRAIN_SECONDS` (D, 30 seconds by default; see [Configuration](../configuration/deployment-profiles.md#server)):

1. The server accepts no new connections. Connections already open keep working. Requests that still arrive on them are answered with `Connection: close`, `/ready` answers `503`, and `/live` answers `200` with `"draining": true`.
2. Every WebSocket receives a close frame with code `1012` ("service restart"). Session-sync clients reconnect once the server is back, with a current access token.
3. Requests in progress finish with complete responses. Background commands use Redis `async`; web has no task workers, CPU pool or media executor. The worker deployment keeps its consumers and encoders while web drains.
4. When the drain window ends (13 seconds at the default), the server closes every response it is still sending, such as an audio stream, without finishing it. The client sees a transfer error, not the end of the track. The server then resets every connection still open.
5. Swoole stops the HTTP workers. Video and audio execution remain in the independent worker deployment; reconnecting clients read the persisted media state and current generation.
6. The server exits with status `0`. A drain that had nothing to wait for takes under a second.

If the server's manager process is still running at the deadline, the drain watchdog, [`app:server:drain:watch`](../commands/app-server-drain-watch.md), kills it with `SIGKILL`, and the server exits with status `1`. The server logs a line when the drain starts, with the deadline, the window and Swoole's `max_wait_time`, and another when the window closes. A planned stop sends no [health alert](../operations/health-and-metrics.md#what-is-checked).

### Stop timeout

Give the container a stop timeout of at least D + 5 seconds, 35 seconds at the default, before the runtime sends `SIGKILL`:

- Docker Compose: `stop_grace_period: 35s` on the app service. The development `docker-compose.yml` sets this.
- `docker run --stop-timeout 35`, or `docker stop -t 35` for one stop.
- Kubernetes: `terminationGracePeriodSeconds: 35`. On other orchestrators, set the equivalent grace period or kill timeout.

When you change `WEB_DRAIN_SECONDS`, change the stop timeout with it.

Docker's default stop timeout is 10 seconds, shorter than even the smallest drain deadline needs (8 + 5 seconds). With it, Docker kills the container 10 seconds into the drain, before the drain window ends. Requests still running then fail, and streams end when their processes die. The web player resumes those streams too, but slow requests lose their responses.

### Listeners during a restart

When its stream fails, or stalls for 10 seconds, the web player asks the server's `/live` endpoint whether the server answers. No answer, a timeout, an answer other than `2xx` or an answer with `"draining": true` means the server is unreachable; nginx answers `502` while the app container restarts, and a draining server can still answer on a connection nginx kept alive. The player then:

- shows "Reconnecting…" in place of the time and keeps the position it had reached;
- retries with a growing, randomised delay, from 1 second up to 15 seconds, and honours a `Retry-After` header on a `503`;
- once `/live` answers, requests the same track again and continues at that position. The resume records no second play.

A transcoded track resumes once its accepted rendition is complete; until then the player shows "Reconnecting…". When the browser will not start playback without a user gesture, the player shows a "Tap to resume" button. After about two minutes without success, the player pauses with its usual failure notice and keeps the position, so pressing play tries again.

A token refresh that fails because the server is restarting, with a network error or a `5xx` answer, keeps the user signed in. Only a `401`, or a `400` with the error `invalid_grant`, from the refresh signs the user out. Video execution stays with the worker during a web restart; see [Transcoding](../media/video-transcoding.md#restarts).

### nginx and a replaced app container

The bundled nginx resolves the `app` host name once, when it starts. A restarted container normally keeps its address. A recreated container can come back with a new one. nginx then keeps sending requests to the old address and answers `502`, and the player gives up after two minutes. After the app container has been replaced, reload nginx so it resolves `app` again:

```bash
dc exec nginx nginx -s reload
```

### A proxy in front of nginx

The production nginx expects a reverse proxy in front of it to terminate TLS. When the web server cuts a stream, that proxy must pass the cut on to the client as an error. Over HTTP/2 a proxy can answer an upstream reset by ending the client's stream normally. The player then takes the cut for the end of the track and skips to the next one.

To check your proxy, copy a stream request from the browser's network tab as a curl command and run it through the proxy with `--limit-rate 32k`, so that it is still downloading when you restart the app container. curl must fail with exit code `18` over HTTP/1.1 or `92` over HTTP/2. Exit code `0` means the proxy ended the stream cleanly.
