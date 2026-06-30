# Getting Started

## Prerequisites

- Docker + Docker Compose
- Node.js 20+ and Yarn 1 (for frontend development)
- WSL2 with Docker integration (Windows only)

## Hosts File

Map `baander.test` to `127.0.0.1` in your hosts file.

## Setup

```bash
# 1. Build the Docker images (first time or after Dockerfile changes)
make build

# 2. Start all services
make start

# 3. Install PHP dependencies
make composer-install

# 4. Run database migrations
make migrate

# 5. Start the frontend dev server
cd ui/web && yarn && yarn dev
```

The app is served at `https://baander.test` (Nginx → Swoole).

## Services

| Service | Container | Host Port | Internal Host |
|---------|-----------|-----------|---------------|
| Nginx | `baander-nginx` | 80 (HTTP), 443 (HTTPS) | — |
| PHP/Swoole | `baander-app` | — | `app` on `baander-backtier` |
| PostgreSQL 18 | `baander-postgres` | 5432 | `postgres` on `baander-backtier` |
| Redis | `baander-redis` | 6379 | `redis` on `baander-backtier` |
| Reli Profiler | `baander-profiler` | — | see [profiling.md](profiling.md) |

### PostgreSQL

- **Host:** `127.0.0.1:5432`
- **Username:** `baander`
- **Password:** `baander`
- **Database:** `baander`

### Redis

- **Host:** `127.0.0.1:6379`
- **Password:** `baander`
- Used for: caching (tag-aware), Messenger transport, SSE Pub/Sub

## Common Commands

All backend commands run inside the `baander-app` container. Use `make` from the host:

```bash
make ssh                # Shell into app container
make ssh-root           # Shell as root
make phpunit            # Run test suite
make phpstan            # Static analysis
make migrate            # Run migrations (main + test DB)
make stop               # Stop all services
make restart            # Restart all services
```

Run arbitrary commands inside the container:

```bash
make exec-bash cmd="php -v"
make exec cmd="ls -la"
```

Frontend commands run on the host (not in Docker):

```bash
cd ui/web
yarn test               # Run Vitest
yarn test:watch         # Watch mode
yarn build              # Production build
```

For functional test conventions (TestCase base class, test authentication via `X-Test-User-Id`, response shape patterns, and bugs discovered by tests), see the [Testing Guide](../docs-book/part-2-developer-guide/testing.md).

## Dev Users

| Role | Email | Password |
|------|-------|----------|
| Admin | `admin@baander.test` | `password` |
| User | `user@baander.test` | `password` |

## Debugging

- **Xdebug**: see [xdebug.md](xdebug.md)
- **PhpStorm setup**: see [phpstorm.md](phpstorm.md)
- **Profiling**: see [profiling.md](profiling.md)
