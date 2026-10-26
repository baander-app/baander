# Development Environment

Use `baander.test` for the persistent instance and a worktree preview for changes.
The persistent instance follows remote `master` in its own checkout. A preview runs
this worktree's PHP and Vite source with a separate database, Redis, keys and
writable storage. Changing an agent branch does not change the persistent checkout.

## Prerequisites and host setup

The controller requires Linux, Python 3, Git, OpenSSL, Docker with Compose and
Buildx, NetworkManager managing DNS through dnsmasq, mkcert, and a working systemd
user manager. Docker must provide cgroup v2 and publish loopback port 443 for the
invoking user. Each worker pair requires at least two daemon CPUs and 8448 MiB of
daemon RAM; allow additional resources for web, dependencies and concurrent
instances. Use Yarn for frontend work; never run npm.

Generate the host instructions before starting an instance:

```bash
make dev-host-setup
```

Read the generated `host-setup/README.md` and configuration files under
`${XDG_STATE_HOME:-$HOME/.local/state}/baander/development`. They describe installing
the NetworkManager dnsmasq configuration with administrator permission and
reloading NetworkManager. Verify that `baander.test` and a subdomain both resolve
to `127.0.0.1`. This DNS change can briefly interrupt network access.

Install the local certificate trust explicitly, after inspecting those instructions:

```bash
make dev-host-setup OPTIONS=--install-trust
```

This invokes `mkcert -install`, which can request administrator permission, and
creates the gateway leaf certificate for `baander.test` and `*.baander.test`.
The private CA stays in host state. `make dev-host-setup OPTIONS=--inspect` checks
DNS ownership, mkcert availability and whether loopback port 443 is free before
first gateway startup. Host setup writes inspectable files; it does not install DNS
configuration for you.

The Docker daemon must see every source and state bind path. On SELinux hosts,
grant containers read access to the selected worktree, media and state directories,
and write access to the state directories that containers update. Configure access
for those selected paths; do not relabel the home directory as a whole. The workflow
does not silently relabel directories or disable SELinux. Resolve denied access
before starting it. A remote daemon cannot read a client-only checkout path.

## Everyday commands

Run these commands from the repository root:

| Command | Result |
|---------|--------|
| `make dev-up` | Start or resume `https://baander.test` and enable automatic remote-master updates |
| `make dev-down` | Disable automatic updates and stop the persistent instance, retaining data |
| `make dev-status` | Inspect persistent revisions, readiness, updater state and failures |
| `make preview-up` | Start this worktree's preview and refresh its worker snapshot |
| `make preview-down` | Stop this worktree's preview, retaining data |

On first startup, the controller creates private configuration, initializes an empty
database, creates OAuth keys and clients, and seeds development accounts. Subsequent
starts reuse that identity and data. Lifecycle commands do not reset databases.
Development credentials are `admin@baander.test` / `admin` and
`user@baander.test` / `user`; keep these instances local.

The persistent updater is a systemd user timer named
`baander-development-<state-root-hash>.timer`. The state-root hash keeps separate
controller state directories from sharing a timer. Inspect installed timers with
`systemctl --user list-timers 'baander-development-*'`.
It checks remote `master` every 60 seconds while enabled. Its controller and clone
live outside worktrees. The user manager must remain available for checks to run;
the workflow does not enable login lingering. A stopped instance stays stopped
until `make dev-up` enables it again.

Updates prepare immutable images before stopping writers. Migration checks run
before advancing the instance, and readiness includes the worker pair. A blocked
migration or uncertain worker retirement requires inspection; repeated timer ticks
do not authorize a reset or database rollback.

## Previews and browser verification

A preview has a stable worktree-specific hostname below `baander.test`. PHP and
Vite read the live worktree. PHP's development watcher reloads included files on
close-write events; atomic saves and configuration changes can require
`make restart-app` or `make preview-up`. Workers run immutable code captured by the most recent
`make preview-up`; run that command again after worker changes. Preview PostgreSQL,
Redis, secrets, caches and generated media are separate from the persistent instance.

```bash
make preview-up
make preview-status
make preview-url
```

Use the URL returned by `make preview-url` for browser work. For Playwright:

```bash
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless
```

The browser runner requires an explicit isolated preview URL. It does not provision
an app or fall back to the persistent instance. See [Testing](testing.md) for the
separate disposable backend coordinator and frontend suites.

## Media mounts

Add a host media directory when starting the persistent instance:

```bash
make dev-up MEDIA=/absolute/path/to/media
make dev-up MEDIA=/absolute/path/to/media=/media/library
```

The optional target preserves a path expected by an existing library. New previews
inherit persistent media mount declarations and mount source media read-only.
Their generated conversions and other writable state remain separate. Stop an
instance before changing its mount declarations. Directory visibility and SELinux
permissions must work on the selected Docker daemon. Media directories must not
contain the private controller state directory or sit inside it.

## Commands and diagnostics

Generic Make commands select this worktree's preview. Select the persistent
instance explicitly with `INSTANCE=persistent`:

```bash
make exec cmd="php bin/console about"
make logs
make exec INSTANCE=persistent cmd="php bin/console about"
make dev-update
make dev-update OPTIONS=--retry
```

`make exec` splits `cmd` into arguments with shell-style quoting, preserving quoted
values as single arguments. Use `make exec-bash` when the command needs shell
operations such as pipes, redirection or variable expansion.

`make dev-update` requests an immediate remote-master check. `--retry` retries a
failed build after its cause has been corrected. Inspect `make dev-status` before
using `make dev-recover`; recovery reconciles a blocked attempt after database
history has been checked. It does not reverse a migration. `make restart` refreshes
the selected instance. `make ssh`, `make logs`, and dependency or migration commands
use the same explicit instance selection.

State defaults to `${XDG_STATE_HOME:-$HOME/.local/state}/baander/development`.
For an isolated controller test or another state directory, use the dispatcher's
`--state-root` option before its subcommand:

```bash
python3 scripts/dev-environment.py --state-root /absolute/private/state status --instance persistent
```

There is no implicit state-root environment override. Keep state private: it holds
credentials, certificates, manifests and recorded Docker identities. The state
root must be outside the checkout.

## Adopting an existing instance

Inspect existing resources before selecting adoption. Do not run a fresh setup or
reset to make adoption pass. Supply two explicit JSON files through the dispatcher:

```bash
make dev-up OPTIONS="--adopt /absolute/private/adoption.json --environment-file /absolute/private/environment.json"
```

The adoption inventory's `containers` object records full immutable Docker IDs for
`postgres`, `redis`, `app`, and optionally `nginx`. Its `worker_manifests` array
records each existing deployment as an object with `directory`, `operator_image`,
`socket`, and `network`. Retain its old controller, manifest and credentials so it
can retire the old workers through their admitted deployment protocol.
The environment file records the existing database identity, application and stream
secrets, VAPID keys, OAuth client configuration and media paths. Protect both files.

Adoption requires `MEDIA_STORAGE_PATH` and `CONVERT_STORAGE_PATH` to be disjoint
from the writable storage root at `/var/www/html/storage`. Legacy paths such as
`/var/www/html/storage/media` overlap that root and are refused before configuration
is saved or writers stop. Keep the original deployment intact until its owner
explicitly relocates those paths; adoption does not relocate them automatically.

The selected PostgreSQL container must have recorded persistent PGDATA. Existing
Redis data, OAuth keys and writable application mounts must also be discoverable.
Missing Redis persistence, incompatible migration history, ambiguous mounts or
mismatched identity cause refusal. Adoption does not make an incompatible database
compatible. Inspect the reported failure and preserve its resources; any manual
data reset requires separate owner approval. An origin change can also require
re-enrolling passkeys.

## Runtime qualification

Implementation and automated checks do not establish that a particular host meets
these prerequisites. The implementation session could not complete a fresh runtime
qualification: mkcert was absent and SELinux denied default container bind reads.
A diagnostic read with container labeling disabled isolated the latter cause; that
is not the workflow's operating configuration. No existing persistent instance was
changed. Full startup, automatic updating and browser/media verification on a
qualified host remain pending.

## Agent instructions

[AGENTS.md](../../AGENTS.md) gives agents repository instructions and links to
canonical rules in `.agents/rules/`. Update affected guidance when conventions change.
