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

[Installation](../../docs-book/part-1-operator-guide/getting-started/installation.md)
gives the complete procedure in order, from the host tooling to the first library
scan. This section and the next state what the preset expects.

Use the production application, worker and operator images from the same source
revision. The example's application, PostgreSQL, nginx and Redis references follow
the repository's existing image conventions; the worker and operator references
are local build tags. Set all six references to images available on the selected
Docker daemon. The deployment command resolves each reference to an immutable
image ID; it does not build or pull images.
The application image must include the compiled web assets in `public/`. From a
clean source checkout, first [build the frontend](../../docs-book/part-1-operator-guide/getting-started/installation.md#2-build-the-web-frontend),
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
`AUTH_TOTP_ENCRYPTION_KEY` must each be at least 32 bytes, and `DB_PASSWORD` and
`REDIS_PASSWORD` at least 16 bytes, or the containers refuse to boot; generate the TOTP
key with `openssl rand -base64 32`. The containers also refuse to boot with `baander`,
the repository's development password, as the Redis or database password, and with a
VAPID private key the repository has committed. Rendering and starting apply the same
rules before anything starts, and also refuse every secret and password the repository
commits, including the test values in `.env.test` that the containers accept. The default listener is
reachable only from the local host; [Gateway and client addresses](#gateway-and-client-addresses)
covers HTTPS and reverse proxies. Keep the configuration file and state directory
accessible only to the operator.

Install the standalone tooling with
`composer --working-dir=scripts install --no-dev --no-interaction`. The host
requires the normal tooling runtime, Docker Compose, and a daemon-visible Unix
socket for the trusted worker operator. The Docker daemon must run rootful;
rendering refuses a rootless daemon, which cannot apply the negative
out-of-memory scores. The web container has no Docker socket.
This preset creates no default account, and self-registration ships closed. After
first startup, run `app:auth:setup-clients` and
[create the first account](../../docs-book/part-1-operator-guide/getting-started/installation.md#6-create-the-sign-in-client-and-the-first-account)
as a super admin with `app:user:create` and `--role=super-admin` in the web container,
through the installation's [command context](../../docs-book/part-1-operator-guide/getting-started/installation.md#command-context).
The examples set `MAILER_DSN` to `null://null`, which discards mail; opening
self-registration on that mailer draws a configuration warning, because new accounts
never receive their verification link. OAuth key
generation is automatic for an empty secrets volume and preserves an existing pair.

## Gateway and client addresses

The gateway serves HTTP on `listen.address` and `listen.port`; the address may be IPv4
or IPv6. The gateway is web's only trusted proxy. It shares the deployment's edge
network, `<name>-edge`, with web alone, and holds a fixed address in each of that
network's two subnets, one IPv4 and one IPv6. The deployment sets `TRUSTED_PROXIES` to
those two addresses and refuses the variable in `environment`.

Because web believes the gateway's forwarding headers, the gateway writes all of them:
`X-Forwarded-For` carries the client address, `X-Forwarded-Host` the request's `Host`
header and `X-Forwarded-Proto` the protocol, and `X-Forwarded-Port` is dropped. Web
therefore rate limits each client under its own address, and a client's own forwarding
headers cannot change the scheme, host or port that web sees. This holds in both
families: the gateway accepts clients over IPv4 and IPv6, and it reaches web over both.
Web listens on `::`, whose IPv6 socket also accepts IPv4 connections. The socket reports
an IPv4 peer such as `10.231.0.2` in mapped form, `::ffff:10.231.0.2`; web converts it
back to the plain address, so proxy trust, rate limits and logs see one address per peer.

For an HTTPS origin, run a TLS reverse proxy in front of the gateway. The gateway
reads the client address from `X-Forwarded-For` and the protocol from
`X-Forwarded-Proto` only on connections from a declared front proxy; for any other
peer it ignores both headers and uses the connection's own address and protocol.
List front proxies in `listen.frontProxies` as IPv4 or IPv6 addresses or CIDR ranges.
Each must append the client address to `X-Forwarded-For` and set `X-Forwarded-Proto`.
A loopback listener, the default, also declares the Docker host; any address in
`127.0.0.0/8` or `::1` counts as loopback. Host connections reach the gateway from the
edge network's bridge address in their own family, which is that subnet's first host
address, so any process on the host can report a client address.

The IPv4 subnet defaults to `10.231.0.0/28` and the IPv6 subnet to the unique local
range `fd00:baa:231::/64`. If a range is in use, set `network.subnet` to another IPv4
network of /29 or larger, or `network.subnet6` to another IPv6 network of /125 or
larger; start refuses a subnet that overlaps another Docker network. In each subnet the
gateway takes the second host address, and Docker assigns web's address from the upper
half, so no container can take the gateway's address first.

Because the edge network carries both families, the Docker daemon must be able to
create IPv6 networks. A daemon that cannot fails start when it creates the edge
network, and the error names `network.ipv6`. Set that to `false` for an IPv4-only edge
network: it has the IPv4 subnet alone, and the configuration must not set
`network.subnet6`. The gateway then holds only its IPv4 address and listens on IPv4,
and web trusts that one address and listens on `0.0.0.0`. Host connections arrive from
the IPv4 bridge address; with Docker's userland proxy, so do host connections to an
IPv6 listen address.

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
role and the pooler's auth file one SCRAM verifier, writes Redis's access control list,
runs pending schema
migrations through the pooler, initializes a missing OAuth key pair, verifies
effective database settings, starts web services and launches the worker pair.
See [Connection pooler](../../docs-book/part-1-operator-guide/configuration/deployment-profiles.md#connection-pooler)
for the pooler's aliases and pools.

Web, the pooler and the worker pair connect to PostgreSQL as `DB_USERNAME`, which
owns the `DB_DATABASE` database and is not a superuser: it cannot create roles or
run programs on the server. PostgreSQL initializes the cluster with the bootstrap
superuser `postgres` and a password generated at each render that no other service
receives. The pooler-credentials helper joins no network; it reaches PostgreSQL's
local socket through the `<name>-postgres-socket` volume, creates the application
and control roles and the seven extensions the migrations use, and clears the
superuser's password. After that, `postgres` logs in only over the local socket, as
the commands in [Backup and recovery](../../docs-book/part-1-operator-guide/maintenance/backup-and-recovery.md#capture-a-maintenance-checkpoint)
do. A data volume that an earlier preset initialized has no `postgres` role, so
`start` stops at the pooler-credentials step with PostgreSQL running. Create the role
over the local socket as the old superuser, in the installation's
[command context](../../docs-book/part-1-operator-guide/getting-started/installation.md#command-context),
then run `start` again; the helper then
removes the superuser attribute from `DB_USERNAME`. This path has not been drilled:

```sh
dc exec -T postgres psql -U <DB_USERNAME> -d <DB_DATABASE> -c 'CREATE ROLE postgres SUPERUSER LOGIN'
```

Redis runs as its image's `redis` user with every capability dropped, and holds no
password in its arguments or environment. A one-off helper from the Redis image
receives `REDIS_PASSWORD` by name, writes an access control list to the
`baander-redis-credentials` volume and gives the `baander-redis` data volume to the
`redis` user. The list turns the `default` user off and admits the application as
the `baander` user with the commands it issues; `KEYS`, `FLUSHALL`, `CONFIG` and the
other dangerous commands are refused. Web and the worker pair connect with
`REDIS_URL` and `MESSENGER_TRANSPORT_DSN`, which the preset derives with the
password percent-encoded, so a password may hold any character.

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
