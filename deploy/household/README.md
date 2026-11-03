# Household deployment preset

This preset runs a gateway, web server, PostgreSQL, Redis and the managed worker
pair. Its limits are enforced reservations, not measured consumption or a minimum
host size. The `full` profile shares the web, Redis and gateway limits, sizes
PostgreSQL for its larger connection pool and preserves its existing worker
budget. [System Requirements](../../docs-book/part-1-operator-guide/system-requirements.md)
lists each reservation beside the consumption measured on 3 November 2026. A 4 GB
host, ARM builds and Raspberry Pi hardware remain unqualified.

## Prepare

Use the production application, worker and operator images from the same source
revision. The example's application, PostgreSQL, nginx and Redis references follow
the repository's existing image conventions; the worker and operator references
are local build tags. Set all six references to images available on the selected
Docker daemon. The deployment command resolves each reference to an immutable
image ID; it does not build or pull images.
The application image must include the compiled web assets in `public/`. From a
clean source checkout, first [build the frontend](../../docs-book/part-1-operator-guide/getting-started.md#6-install-and-build-the-web-frontend),
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

Copy `example.json` to a private file, replace every secret and set the origin and
listening address for your installation. The gateway serves HTTP. For an HTTPS
origin, configure an external TLS reverse proxy and the application's trusted proxy
settings for that proxy. The default listener is reachable only from the local host.
Keep the configuration file and state directory accessible only to the operator.

Install the standalone tooling loader with `composer tooling:autoload`. The host
requires the normal tooling runtime, Docker Compose, and a daemon-visible Unix
socket for the trusted worker operator. The web container has no Docker socket.
The account bootstrap commands documented in the operator guide remain applicable;
this preset creates no default account. After first startup, run `app:auth:setup-clients`
and [create an administrator](../../docs-book/part-1-operator-guide/user-management.md)
in the web container. OAuth key
generation is automatic for an empty secrets volume and preserves an existing pair.

## Render and start

Use an absolute path for the private state directory:

```sh
php scripts/deploy-household.php render --config /srv/baander/household.json --state /srv/baander/household-state
php scripts/deploy-household.php start --config /srv/baander/household.json --state /srv/baander/household-state
php scripts/deploy-household.php status --state /srv/baander/household-state
php scripts/deploy-household.php stop --state /srv/baander/household-state
```

Rendering validates image profiles and saves the private Compose document and
release identity. Start creates owned named volumes, loads the selected PostgreSQL
configuration, runs pending schema migrations, initializes a missing OAuth key
pair, verifies effective database settings, starts web services and launches the
worker pair. The reported reservation includes workers, services, the operator
and host headroom. Redis's dataset limit is reported separately from its container
limit to leave room for persistence.

The household profile permits original media delivery and stream copy, with one
ordinary consumer and two shared copy slots. It forbids audio and video encoding.
Set `profile` to `full` to select full operation. Starting again retires the old
worker pair before installing the selected release; no live profile toggle exists.

Stop retires workers and removes service containers and the private network. It
retains every named data volume. Application code caches remain inside each
container, while media, storage, conversions, transcode state and OAuth keys
persist separately. The media volume is mounted read-only for playback and workers;
populate it through a separately authorized import into that named volume.

If startup fails, the saved phase and worker record remain in the state directory.
Correct the cause and run `start` again; it first completes retirement and stops the
saved services. Preserve the state directory when recovering ownership. The command
refuses foreign volumes, networks or containers with matching names. Derived
configuration volumes are retained and listed in state for qualification cleanup.
