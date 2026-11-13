# Quick Start

You have cloned the repository and want a working local Baander instance with the
least ceremony. This guide sets up wildcard `.baander.test` DNS, installs a trusted
local certificate authority, and starts an isolated worktree preview with seeded
accounts and background workers.

For the persistent `baander.test` instance, media library mounts, custom Docker
targets, and recovery commands, see
[Development Environment](development-environment.md).

## Prerequisites

The host controller runs on Linux and requires:

- PHP 8.5+ with the `swoole` extension, and Composer
- Docker with Compose and Buildx, cgroup v2, and loopback port `443` free and
  publishable by your user (at least 2 daemon CPUs and 8448 MiB of daemon RAM for
  the worker pair, plus headroom for the web stack)
- NetworkManager managing DNS through `dnsmasq`, `mkcert`, a working `systemd` user
  manager, Git, Bash, GNU coreutils, `setsid`, and OpenSSL

## 1. Install the host controller

From the repository root, install the standalone controller dependencies under
`scripts/`:

```bash
composer --working-dir=scripts install --no-dev
```

## 2. Configure `.baander.test` DNS

Each worktree preview runs on its own `*.baander.test` subdomain behind a local
HTTPS gateway on port `443`. Generate the host setup files:

```bash
make dev-host-setup
```

This writes `10-baander-dns.conf`, `baander.conf`, and `README.md` to
`${XDG_STATE_HOME:-$HOME/.local/state}/baander/development/host-setup`. Install the
two NetworkManager `dnsmasq` files and reload NetworkManager (reloading DNS can
briefly interrupt network access):

```bash
STATE_DIR="${XDG_STATE_HOME:-$HOME/.local/state}/baander/development/host-setup"
sudo cp "$STATE_DIR/10-baander-dns.conf" /etc/NetworkManager/conf.d/10-baander-dns.conf
sudo mkdir -p /etc/NetworkManager/dnsmasq.d
sudo cp "$STATE_DIR/baander.conf" /etc/NetworkManager/dnsmasq.d/baander.conf
sudo systemctl reload NetworkManager
```

Confirm that both `baander.test` and a subdomain resolve to `127.0.0.1`:

```bash
getent hosts baander.test preview.baander.test
```

## 3. Trust the local certificate authority

Install a local development CA into your trust stores with `mkcert` and issue the
gateway certificate for `baander.test` and `*.baander.test`:

```bash
make dev-host-setup OPTIONS=--install-trust
make dev-host-setup OPTIONS=--inspect
```

`--install-trust` keeps the private CA under
`${XDG_STATE_HOME:-$HOME/.local/state}/baander/development/host-setup/ca` and may
prompt for administrator permission. `--inspect` verifies that NetworkManager owns
DNS, `mkcert` is available, and loopback port `443` is free.

On SELinux hosts, grant containers access to the checkout and managed state paths
before starting an instance:

```bash
make dev-host-setup OPTIONS="--share-path $(pwd)"
```

## 4. Start your worktree preview

The application image copies FFmpeg from the project's static FFmpeg image, a public
package on GHCR pinned by digest. If your Docker daemon cannot pull it from the
container registry, build a local copy once and point `BAANDER_FFMPEG_IMAGE` at it:

```bash
docker buildx build -f docker/ffmpeg/Dockerfile -t ffmpeg-baander-static:local --load .
export BAANDER_FFMPEG_IMAGE=ffmpeg-baander-static:local
```

Start the preview for this checkout and print its URL:

```bash
make preview-up
make preview-url
```

On first startup, the controller builds the runtime images, initializes PostgreSQL
and Redis, generates OAuth and VAPID keys, starts the background worker pair, and
seeds two local development accounts:

| Role | Email | Password |
|------|-------|----------|
| Administrator | `admin@baander.test` | `admin` |
| User | `user@baander.test` | `user` |

Open the URL printed by `make preview-url` in your browser and sign in.

## 5. Everyday workflow

- **Edit web and backend code:** PHP and Vite read your live checkout. Vite updates
  the browser over HTTPS HMR, and PHP reloads included files on save. If an atomic
  save or configuration change is not picked up, run `make restart-app`.
- **Refresh workers after worker changes:** Background workers run an immutable
  snapshot captured during `make preview-up`. Run `make preview-up` again after
  changing worker or message-handler code.
- **Run console commands and inspect logs:**

  ```bash
  make exec cmd="php bin/console about"
  make logs
  ```

- **Check status or stop the preview:** Stopping the preview keeps its database,
  keys, and media storage intact for the next `make preview-up`.

  ```bash
  make preview-status
  make preview-down
  ```

## Next steps

- [Development Environment](development-environment.md) — persistent `baander.test`
  instance, mounting media libraries via `dev.local.json`, Docker target options,
  and state recovery
- [Frontend Development](../development/frontend-development.md) — working in `ui/web/` with Yarn,
  React, TypeScript, and Orval
- [Testing](../testing/README.md) — backend PHPUnit runners, frontend Vitest suites,
  and Playwright browser checks against `make preview-url`
- [Adding a New Feature](../development/adding-a-feature.md) and
  [Coding Conventions](../development/coding-conventions.md) — bounded-context layers and codebase
  rules
