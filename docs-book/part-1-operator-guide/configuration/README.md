# Configuration

Environment variables configure service connections, paths, secrets and optional
integrations. Symfony's configuration reads those values when the application
starts. The [source installation](../getting-started/installation.md) uses private web settings
and a separate worker credentials file; changing one does not update the other.

Behavior that an administrator may want to change while the server runs, such as whether audio is transcoded or which language emails default to, is kept in the database instead.

| Guide | Scope |
|-------|-------|
| [Deployment Capability, Size and Runtime](deployment-profiles.md) | `transcode` and `remux` capabilities, size settings and starting points, media slots, PgBouncer connection pooler, Swoole server drain budget, coroutine file I/O, and PHP memory limits |
| [Environment Variables](environment-variables.md) | Application, PostgreSQL, Redis, Messenger, Web Push, mail, external APIs, scanner security, discovery, media storage, stream authentication, CORS, and job monitoring |
| [Authentication and Rate Limiting](authentication-and-limits.md) | OAuth 2.0 keys, token lifetimes, authorization server parameters, rate limiters, trusted proxies, passkeys, Argon2id, password reset, email verification, and token binding |
| [Server and User Settings](server-settings.md) | Live `system_settings` via Admin UI/CLI/API, settings reference, scheduled recommendations, email language selection, and per-user settings |

## Where a change takes effect

| Configuration | Applies to | How a change takes effect |
|---------------|------------|---------------------------|
| Web environment and Symfony configuration | Web application and console commands launched with that environment | Restart the running app after changing environment values; use the [upgrade procedure](../maintenance/upgrading.md) for release or image changes. A new console process reads its own environment. |
| Worker image and admitted credentials | The control/execution worker pair | Follow [worker deployment](../workers/worker-deployment.md) and [upgrading](../maintenance/upgrading.md). Changed deployment recipes require retirement and a fresh boot; editing a file does not alter running containers. |
| Worker manifest | Host controller and the admitted profile/storage recipe | Use the exact manifest for observation and recovery. Changes to its profile or recipe require a new admitted deployment. |
| Server settings | Application behavior that reads a setting while running | Use the admin UI, settings CLI or API described below. Each feature reads the setting when it acts; already completed work is not undone. |
| User settings | One user's explicitly stored preferences | Change through the account UI, admin tools or user settings API. Removing a choice restores the applicable default. |

For the source-installation web environment, Symfony reads `.env`, `.env.local`,
`.env.dev` and `.env.dev.local` in that order; existing process environment values
win. The tracked `.env.dev` has development defaults, including an application
secret, so put private overrides in `.env.dev.local` as shown in
[Getting started](../getting-started/installation.md#1-prepare-configuration-and-the-browser-address).
A `prod` process selects its corresponding environment files instead. Do not put
secrets in files copied into an image.

Compose's `--env-file` options configure Compose interpolation separately from
Symfony's environment loading. The installation's `dc` function names both the
private settings and generated image-selection file. Use that function for its
commands; Make targets the development preview. See [Command context](../getting-started/installation.md#command-context).

The worker pair runs with `APP_ENV=prod`. It does not read the web's
`.env.dev.local`. Its admitted credentials allow a restricted set of runtime keys;
other values, including encoder selection, must be configured in the image as
described by the worker and transcoding guides. Web, workers and host controller
must reach the same main database through addresses appropriate to their networks.
Web and workers must also agree on shared media paths and Redis transport settings.
See [Shared media paths](../getting-started/storage-and-data.md#shared-media-paths).

## Protect configuration

- Keep deployment credentials out of Git and image build contexts. The source
  installation uses `.env.dev.local`; the worker credentials file has its own
  ownership and mode requirements in the [worker deployment reference](../workers/worker-deployment.md#ipc-and-media-volumes).
- All env vars in `.env` can be overridden by setting real environment variables in your container runtime (Docker Compose, Kubernetes, etc.).
- For each deployment, generate a unique `APP_SECRET` with `php -r 'echo bin2hex(random_bytes(32));'`.
- OAuth keys should be at least 2048-bit RSA. Generate with `openssl genrsa -out private.key 2048` and `openssl rsa -in private.key -pubout > public.key`.
- Rate limiting requires Redis. Unavailable state stops protected work with `503`; logout and revocation retain the narrow availability exceptions described above.
