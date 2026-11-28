# Configuration

Environment variables configure service connections, paths, secrets and optional
integrations. Symfony's configuration reads those values when the application
starts. An [installation](../getting-started/installation.md) keeps them in the deployment
preset's private configuration file. Its `environment` object holds the operator's
values, and the preset derives the rest; web and the worker pair receive both from
the same file when the deployment starts.

Behavior that an administrator may want to change while the server runs, such as
whether audio is transcoded or which language emails default to, lives in the
database instead.

| Guide | Scope |
|-------|-------|
| [Deployment Capability, Size and Runtime](deployment-profiles.md) | `transcode` and `remux` capabilities, size settings and starting points, media slots, PgBouncer connection pooler, Swoole server drain budget, coroutine file I/O, and PHP memory limits |
| [Environment Variables](environment-variables.md) | Application, PostgreSQL, Redis, Messenger, Web Push, mail, external APIs, scanner security, discovery, media storage, stream authentication, CORS, and job monitoring |
| [Authentication](authentication-and-limits.md) | OAuth 2.0 keys, token lifetimes, authorization server parameters and token binding |
| [Account Security](account-security.md) | Passkeys, Argon2id, password reset and email verification configuration |
| [Rate Limits](rate-limits.md) | Limiter defaults, route accounting, trusted proxies and outage behavior |
| [Server and User Settings](server-settings.md) | Live `system_settings` via Admin UI/CLI/API, settings reference, scheduled recommendations, email language selection, and per-user settings |

## Where a change takes effect

| Configuration | Applies to | How a change takes effect |
|---------------|------------|---------------------------|
| Deployment configuration: `environment`, `origin`, `listen`, `capability`, `size` | Web, console commands run in the web container, and the worker pair | Edit the private configuration file, then run the preset's `start`, which retires the worker pair, stops the services and starts the deployment with the new values. Use the [upgrade procedure](../maintenance/upgrading.md) for release or image changes. A console command in the web container reads that container's environment, so it sees a change only after `start`. |
| Worker image and admitted credentials | The control/execution worker pair | The preset takes the worker image from its configuration's `images` and renders the credentials on every `start`. For a pair managed separately, follow [worker deployment](../workers/worker-deployment.md) and [upgrading](../maintenance/upgrading.md). Changed deployment recipes require retirement and a fresh boot; editing a file does not alter running containers. |
| Worker manifest | Host controller and the admitted profile/storage recipe | The preset writes it into its state directory. Use the exact manifest for observation and recovery. Changes to its profile or recipe require a new admitted deployment. |
| Server settings | Application behavior that reads a setting while running | Use the admin UI, settings CLI or API described below. Each feature reads the setting when it acts; already completed work is not undone. |
| User settings | One user's explicitly stored preferences | Change through the account UI, admin tools or user settings API. Removing a choice restores the applicable default. |

An installation's private configuration file, written in
[Installation](../getting-started/installation.md#4-write-the-private-configuration),
sets the operator's variables in its `environment` object. The preset refuses the
variables it derives: `APP_ENV`, always `prod`, and `APP_DEBUG`; `APP_URL`,
`DEFAULT_URI` and `APP_DOMAIN` from `origin`; `DATABASE_URL` and
`DATABASE_CONTROL_URL` from `DB_USERNAME`, `DB_PASSWORD` and `DB_DATABASE`;
`REDIS_URL` and `MESSENGER_TRANSPORT_DSN` from `REDIS_PASSWORD`;
`MEDIA_STORAGE_PATH`, `CONVERT_STORAGE_PATH`, `APP_HOME` and the OAuth key paths;
`BAANDER_DEPLOYMENT_PROFILE` from `capability` and `size`; and `TRUSTED_PROXIES`,
which names the gateway. Declare a reverse proxy in front of the gateway in
`listen.frontProxies` instead. The image's `.env`, copied from `.env.example` when
the image is built, supplies defaults for everything else, and a variable in
`environment` overrides it. Do not put secrets in files copied into an image.

Console commands for an installation run in its web container through the `dc`
function in [Command context](../getting-started/installation.md#command-context), and
read that container's environment. Make targets the development preview instead.

The worker pair runs with `APP_ENV=prod` and receives a fixed subset of the web
environment: the derived connection and media path values, `APP_URL`,
`DEFAULT_URI` and `BAANDER_DEPLOYMENT_PROFILE`, the three secrets,
`REDIS_PASSWORD`, `MAILER_DSN`, `MAIL_FROM_ADDRESS`, `VIDEO_ENCODER`, the VAPID
pair, `OTEL_ENABLED`, `OTEL_EXPORTER_OTLP_ENDPOINT`, `TMDB_BEARER_TOKEN` and
`TVDB_API_KEY`.
Any other `environment` key reaches web alone. Other worker values, including
`HW_ACCEL_DEVICE` and `HW_BITRATE_MULTIPLIER`, come from the image as the worker
and transcoding guides describe. The preset gives web and workers the same database,
Redis and media paths. See [Shared media paths](../getting-started/storage-and-data.md#shared-media-paths).

## Video metadata credentials

Set `TVDB_API_KEY` to the installation's licensed TheTVDB v4 API key and
`TMDB_BEARER_TOKEN` to its TMDB API read access token. Both values reach web and
workers. After editing the installation's private configuration, run the preset's
`start` so both use the new credentials. A development preview picks up its changed
configuration through `make preview-up`.

An empty key leaves that provider unconfigured while the application and workers
start normally. Provider switches are database settings and take effect on the next
check. Use [app:metadata:providers](../commands/app-metadata-providers.md) to inspect
the enabled and configured states, and
[app:metadata:provider:set](../commands/app-metadata-provider-set.md) to change a switch.

The [preset worker environment](../../../packages/baander-deployment/src/Presets/Renderer.php)
and [worker environment admission](../../../src/Shared/Infrastructure/Worker/DeploymentRuntimeEnvironment.php)
carry these credentials. The
[empty-key kernel test](../../../tests/Functional/Metadata/ProviderKeysEmptyKernelTest.php)
checks that both adapters resolve without credentials.

## Protect configuration

- Keep deployment credentials out of Git and image build contexts. An installation
  keeps them in its private configuration file and state directory; keep both
  accessible only to the operator. A separately managed worker pair's credentials
  file has its own ownership and mode requirements in the
  [worker deployment reference](../workers/worker-deployment.md#ipc-and-media-volumes).
- A real environment variable set in the container runtime, such as Docker Compose
  or Kubernetes, overrides the same variable in `.env`.
- For each deployment, generate a unique `APP_SECRET` and `STREAM_URL_HMAC_SECRET` with `php -r 'echo bin2hex(random_bytes(32));'`, and an `AUTH_TOTP_ENCRYPTION_KEY` with `openssl rand -base64 32`. In `prod`, every process refuses to boot while one of the three is unset, a committed default, a `replace-with-` placeholder or shorter than 32 bytes.
- OAuth keys should be at least 2048-bit RSA. Generate with `openssl genrsa -out private.key 2048` and `openssl rsa -in private.key -pubout > public.key`.
- Rate limiting requires Redis. While its state is unavailable, protected work stops
  with `503`; logout and revocation keep the narrow exceptions described in
  [Sessions, logout and a Redis outage](../../part-2-developer-guide/development/session-and-access-behavior.md#sessions-logout-and-a-redis-outage).
