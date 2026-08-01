# CLI Commands

Baander ships a set of console commands for managing users, libraries, metadata, scheduler jobs, and more. All commands run inside the app container.

```bash
# Run any command
make exec cmd="php bin/console <command>"

# Example
make exec cmd="php bin/console app:user:create --help"
```

## Auth & Users

| Command | Description |
|---------|-------------|
| [app:auth:rotate-secrets](app-auth-rotate-secrets.md) | Rotate OAuth keys and invalidate all tokens |
| [app:auth:setup-clients](app-auth-setup-clients.md) | Create OAuth2 password clients for the SPA and Electron app |
| [app:oauth:generate-keys](app-oauth-generate-keys.md) | Generate OAuth2 private and public keys for JWT signing |
| [app:user:create](app-user-create.md) | Create a new user account |
| [app:user:disable](app-user-disable.md) | Disable a user account |
| [app:user:enable](app-user-enable.md) | Enable a previously disabled user account |

## Libraries & Catalog

| Command | Description |
|---------|-------------|
| [app:library:create](app-library-create.md) | Register a new media library |
| [app:library:scan](app-library-scan.md) | Scan a media library for new files |
| [app:albums:extract-covers](app-albums-extract-covers.md) | Extract embedded cover art for albums missing one |
| [app:watch-files](app-watch-files.md) | Watch directories for filesystem changes |

## Media & Lyrics

| Command | Description |
|---------|-------------|
| [app:images:prune-missing](app-images-prune-missing.md) | Remove image records whose files no longer exist on disk |
| [baander:lyrics:fetch](baander-lyrics-fetch.md) | Bulk-fetch lyrics from LRCLIB for songs missing them |

## Radio

| Command | Description |
|---------|-------------|
| [app:radio:sync](app-radio-sync.md) | Sync stations for all subscribed countries |

## Recommendations

| Command | Description |
|---------|-------------|
| [app:recommendations:generate](app-recommendations-generate.md) | Generate music recommendations using all available strategies |

## Scheduler

| Command | Description |
|---------|-------------|
| [app:scheduler:list](app-scheduler-list.md) | List all scheduled jobs |
| [app:scheduler:run](app-scheduler-run.md) | Manually trigger a scheduled job by ID |

## Notifications

| Command | Description |
|---------|-------------|
| [app:generate-vapid-keys](app-generate-vapid-keys.md) | Generate VAPID keys for push notifications |

## System & Diagnostics

| Command | Description |
|---------|-------------|
| [app:config:validate](app-config-validate.md) | Validate application configuration and check for misconfigurations |
| [app:health:check](app-health-check.md) | Check the health of all system components |
| [app:cli:manifest](app-cli-manifest.md) | Output a JSON manifest of all CLI commands and tooling metadata |
| [app:monitor:prune](app-monitor-prune.md) | Prune completed job monitors older than a given age |
| [debug:hw-transcode](debug-hw-transcode.md) | Show resolved hardware encoder profile and sample FFmpeg commands |

## Development & Docs

| Command | Description |
|---------|-------------|
| [app:dev:setup](app-dev-setup.md) | Bootstrap the dev environment (migrations, keys, clients, users) |
| [app:dev:create-users](app-dev-create-users.md) | Create the standard development users |
| [app:export-openapi-spec](app-export-openapi-spec.md) | Export the OpenAPI spec to a file |
| [app:generate-docs](app-generate-docs.md) | Generate the documentation site from source code and docs-book/ |
