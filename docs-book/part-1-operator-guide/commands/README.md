# CLI Commands

Baander ships a set of console commands for managing users, libraries, notifications, and more. All commands run inside the app container.

```bash
# Run any command
make exec cmd="php bin/console <command>"

# Example
make exec cmd="php bin/console app:user:create --help"
```

## Auth

| Command | Description |
|---------|-------------|
| [app:auth:rotate-secrets](app-auth-rotate-secrets.md) | Rotate OAuth keys and invalidate all tokens |
| [app:auth:setup-clients](app-auth-setup-clients.md) | Create OAuth2 password clients for the SPA and Electron app |
| [app:user:create](app-user-create.md) | Create a new user account |

## Library

| Command | Description |
|---------|-------------|
| [app:albums:extract-covers](app-albums-extract-covers.md) | Extract embedded cover art for albums missing one |
| [app:library:create](app-library-create.md) | Register a new media library |
| [app:library:scan](app-library-scan.md) | Scan a media library for new files |

## Notifications

| Command | Description |
|---------|-------------|
| [app:generate-vapid-keys](app-generate-vapid-keys.md) | Generate VAPID keys for push notifications |

## Development

| Command | Description |
|---------|-------------|
| [app:export-openapi-spec](app-export-openapi-spec.md) | Export the OpenAPI spec to a file |
| [app:watch-files](app-watch-files.md) | Watch directories for filesystem changes |

## System

| Command | Description |
|---------|-------------|
| [app:config:validate](app-config-validate.md) | Validate application configuration and check for misconfigurations |
| [app:health:check](app-health-check.md) | Check the health of all system components |
