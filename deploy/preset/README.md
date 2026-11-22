# Deployment preset

This preset runs a gateway, web server, PostgreSQL, a connection pooler, Redis and
the managed worker pair. No service or worker unit carries a CPU, memory or PID
limit or a CPU set; Redis keeps only its own dataset limit,
`resources.redisMaxmemoryMiB` (default 192). The configuration's `capability` and
`size` bound the work instead: web workers, background consumers, media slots and
PostgreSQL memory. Each service gets an out-of-memory score so that web and
media work are killed before Redis, PostgreSQL, the pooler and the control worker.
[Deployment capability and size](../../docs-book/part-1-operator-guide/configuration/deployment-profiles.md#deployment-capability-and-size)
lists the settings, their bounds and the starting points;
[System Requirements](../../docs-book/part-1-operator-guide/getting-started/system-requirements.md)
states which starting points are qualified. ARM builds and Raspberry Pi hardware
remain unqualified.

## Prepare

Use the production application, worker and operator images from the same source
revision. The example's application, PostgreSQL, nginx and Redis references follow
the repository's existing image conventions; the worker and operator references
are local build tags. Set all six references to images available on the selected
Docker daemon. The deployment command resolves each reference to an immutable
image ID; it does not build or pull images.
The application image must include the compiled web assets in `public/`. From a
clean source checkout, first [build the frontend](../../docs-book/part-1-operator-guide/getting-started/installation.md#6-install-and-build-the-web-frontend),
then build the three application images with the same revision:

```sh
revision=$(git rev-parse HEAD)
php scripts/build-application.php --target production --revision "$revision" --tag baander-app:local
php scripts/build-application.php --target worker --revision "$revision" --tag baander-worker:local
php scripts/build-application.php --target dev-operator --revision "$revision" --tag baander-operator:local
```

Set the example's application reference to `baander-app:local`. The `dev-operator`
target contains the trusted worker controller and Docker client; it uses the
production application layer. These builds do not compile the frontend. Keep the
checkout and compiled assets unchanged between them so all three contain the same
application code.

Copy the example for the nearest starting point and capability, such as
`example-4gb-remux.json` or `example-16gb-transcode.json`, to a private file, adjust
its `size` if needed, replace every secret and set the origin and
listening address for your installation. Rendering and starting refuse any value that
still begins with `replace-with-`. `APP_SECRET`, `STREAM_URL_HMAC_SECRET` and
`AUTH_TOTP_ENCRYPTION_KEY` must each be at least 32 bytes, or the containers refuse to
boot; generate the TOTP key with `openssl rand -base64 32`. The default listener is
reachable only from the local host; [Gateway and client addresses](#gateway-and-client-addresses)
covers HTTPS and reverse proxies. Keep the configuration file and state directory
accessible only to the operator.

Install the standalone tooling loader with `composer tooling:autoload`. The host
requires the normal tooling runtime, Docker Compose, and a daemon-visible Unix
socket for the trusted worker operator. The Docker daemon must run rootful;
rendering refuses a rootless daemon, which cannot apply the negative
out-of-memory scores. The web container has no Docker socket.
The account bootstrap commands documented in the operator guide remain applicable;
this preset creates no default account. After first startup, run `app:auth:setup-clients`
and [create an administrator](../../docs-book/part-1-operator-guide/administration/user-management.md)
in the web container. OAuth key
generation is automatic for an empty secrets volume and preserves an existing pair.

## Gateway and client addresses

The gateway serves HTTP on `listen.address` and `listen.port` and is web's only
trusted proxy. It shares the deployment's edge network, `<name>-edge`, with web alone
and holds a fixed address there; the deployment sets `TRUSTED_PROXIES` to that address
and refuses the variable in `environment`. Because web believes the gateway's
forwarding headers, the gateway writes all of them: `X-Forwarded-For` carries the
client address, `X-Forwarded-Host` the request's `Host` header and
`X-Forwarded-Proto` the protocol, and `X-Forwarded-Port` is dropped. Web therefore
rate limits each client under its own address, and a client's own forwarding headers
cannot change the scheme, host or port that web sees.

For an HTTPS origin, run a TLS reverse proxy in front of the gateway. The gateway
reads the client address from `X-Forwarded-For` and the protocol from
`X-Forwarded-Proto` only on connections from a declared front proxy; for any other
peer it ignores both headers and uses the connection's own address and protocol.
List front proxies in `listen.frontProxies` as IP addresses or CIDR ranges. Each
must append the client address to `X-Forwarded-For` and set `X-Forwarded-Proto`. A
loopback listener, the default, also declares the Docker host: host connections reach
the gateway from the edge network's bridge address, the subnet's first host address,
so any process on the host can report a client address.

The edge subnet defaults to `10.231.0.0/28`. If that range is in use, set
`network.subnet` to another IPv4 network of /29 or larger; start refuses a subnet
that overlaps another Docker network. The gateway takes the subnet's second host
address. Docker assigns web's address from the subnet's upper half, so no container
can take the gateway's address first.

## Render and start

Use an absolute path for the private state directory:

```sh
php scripts/deploy-preset.php render --config /srv/baander/preset.json --state /srv/baander/preset-state
php scripts/deploy-preset.php start --config /srv/baander/preset.json --state /srv/baander/preset-state
php scripts/deploy-preset.php status --state /srv/baander/preset-state
php scripts/deploy-preset.php stop --state /srv/baander/preset-state
```

Rendering asks each image to build the deployment profile from the capability and
size, which refuses values outside their bounds, renders the PostgreSQL
configuration from the size, and saves the private Compose document and release
identity. Start creates owned named volumes, loads the rendered PostgreSQL
configuration, gives the database
role and the pooler's auth file one SCRAM verifier, runs pending schema
migrations through the pooler, initializes a missing OAuth key pair, verifies
effective database settings, starts web services and launches the worker pair.
See [Connection pooler](../../docs-book/part-1-operator-guide/configuration/deployment-profiles.md#connection-pooler)
for the pooler's aliases and pools.

The `remux` capability permits original media delivery and stream copy from its
`copySlots`; it forbids audio and video encoding and refuses `videoSlots`,
`audioSlots` and a `hardware` encoder. The `transcode` capability runs `videoSlots`
and `audioSlots`, which take the application's defaults when unset, and accepts an
optional top-level `hardware` encoder; see
[Media slots](../../docs-book/part-1-operator-guide/configuration/deployment-profiles.md#media-slots).
OpenTelemetry export stays off unless the configuration's `environment` sets
`OTEL_ENABLED` and `OTEL_EXPORTER_OTLP_ENDPOINT`. Changing the capability or size
takes effect when the deployment starts again, which retires the old worker pair
before installing the selected release; no live toggle exists.

Stop retires workers and removes service containers and the private and edge networks. It
retains every named data volume. Application code caches remain inside each
container, while media, storage, conversions, transcode state and OAuth keys
persist separately. The media volume is mounted read-only for playback and workers;
populate it through a separately authorized import into that named volume.

If startup fails, the saved phase and worker record remain in the state directory.
Correct the cause and run `start` again; it first completes retirement and stops the
saved services. Preserve the state directory when recovering ownership. The command
refuses foreign volumes, networks or containers with matching names. Derived
configuration volumes are retained and listed in state for qualification cleanup.
