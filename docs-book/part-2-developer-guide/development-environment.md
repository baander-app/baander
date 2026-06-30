# Development Environment

All backend development runs inside Docker containers. You work from the host using `make` commands, which route into the app container automatically.

## Prerequisites

- Docker and Docker Compose
- Git
- Yarn (for frontend development — never use npm)

## Setup

```bash
git clone <repository-url> baander
cd baander

cp .env.example .env
make build-ffmpeg   # Build FFmpeg static image (first time only)
make build          # Build dev environment
make start          # Start containers
make composer-install  # Install PHP dependencies
make migrate        # Run database migrations
```

## Makefile Commands

### Build

| Command | Description |
|---------|-------------|
| `make build` | Build dev environment (alias for `build-dev`) |
| `make build-dev` | Build dev environment |
| `make build-prod` | Build production image |
| `make build-clean` | Build without cache |
| `make build-ffmpeg` | Build FFmpeg 8.0 static image |
| `make build-all` | Build FFmpeg image then full dev environment |

### Lifecycle

| Command | Description |
|---------|-------------|
| `make start` | Start dev environment |
| `make stop` | Stop containers |
| `make down` | Stop and remove containers and networks |
| `make restart` | Stop and start |
| `make restart-app` | Restart only the app container |

### Shell Access

| Command | Description |
|---------|-------------|
| `make ssh` | Bash shell inside app container (as www-data) |
| `make ssh-root` | Bash shell as root |
| `make exec cmd="..."` | Run a single command in the container |
| `make exec-bash cmd="..."` | Run a shell command string in the container |

### Dependencies

| Command | Description |
|---------|-------------|
| `make composer-install` | Install PHP dependencies (with dev) |
| `make composer-install-no-dev` | Install PHP dependencies (production only) |
| `make composer-update` | Update all PHP dependencies |

### Database

| Command | Description |
|---------|-------------|
| `make migrate` | Run pending migrations (main + test DB) |
| `make migrate-no-test` | Run migrations on main DB only |
| `make drop-migrate` | Drop and recreate both databases, then migrate |

### Testing & Analysis

| Command | Description |
|---------|-------------|
| `make phpunit` | Run PHPUnit with coverage reports |
| `make phpstan` | Run PHPStan static analysis |
| `make phpstan-baseline` | Generate PHPStan baseline |

### Diagnostics

| Command | Description |
|---------|-------------|
| `make info` | Show PHP, Symfony, and Composer versions |
| `make config-check` | Validate application configuration |
| `make logs` | Follow app container logs |
| `make hl-logs` | App logs piped through hl (highlighted) |
| `make swoole-dev` | Clear cache and start Swoole dev server (foreground) |

## Running the App

The app runs under Swoole in process mode. In production it's managed by supervisord inside the container. For development with hot module replacement:

```bash
make swoole-dev
```

This clears the cache and starts the Swoole server in the foreground. Swoole's HMR automatically picks up PHP file changes.

## Xdebug

Xdebug is included in the dev image. Control it via the `XDEBUG_CONFIG` environment variable at the top of the Makefile:

```makefile
XDEBUG_CONFIG=main    # Enable Xdebug
XDEBUG_CONFIG=off     # Disable Xdebug (faster)
```

The `XDEBUG_VERSION` variable controls which Xdebug version is installed (default: 3.5.0).

### PHPStorm

1. Set `XDEBUG_CONFIG=main` in the Makefile
2. Restart the app container: `make restart-app`
3. Configure path mappings: local project root → `/var/www/html` in the container
4. Start a "Listen for PHP Debug Connections" session

### VSCode

1. Install the PHP Debug extension
2. Set `XDEBUG_CONFIG=main` in the Makefile
3. Restart: `make restart-app`
4. Add a launch configuration with `pathMappings` from local root to `/var/www/html`

## Frontend Development

The web frontend lives in `ui/web/`. See the [Frontend Development](frontend-development.md) page for setup and commands.

## Relationship to CLAUDE.md

This page is the canonical human-readable development guide. CLAUDE.md serves the same purpose for AI coding assistants (Claude Code, Cursor). They overlap but are maintained independently — changes to one don't automatically update the other.
