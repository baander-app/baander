# Continuous Integration

A commit on `master` reaches two forges. A forge is a host for the repository that
runs its CI: the self-hosted Forgejo instance (the `origin` remote) and the public
GitHub repository `baander-app/baander` (the `baander-github` remote). Both forges
run the same workflow files from `.github/workflows`, so a commit pushed to both
passes the same gates on each, and the public repository shows the same verdict
that Forgejo gives.

Each forge supplies its own runner, registry, cache store and coverage-baseline
store. The workflows select them at run time; nothing is edited per forge.

## Workflows

| File | Name | Trigger | Does |
| --- | --- | --- | --- |
| `ci.yaml` | CI | Pushes to `master` and pull requests | Backend, frontend, DSP and Registry gates, then builds the production application image and pushes it from `master` |
| `frontend.yaml` | Frontend CI | Pushes and pull requests that touch the frontend paths | Quality checks, tests with coverage, browser suites and the production build |
| `website.yaml` | Public website | Pushes and pull requests that touch the website paths | Builds, validates and browser-checks the static website, then keeps the archive |
| `dsp-analysis.yaml` | DSP analysis qualification | Pushes and pull requests that touch the DSP paths | Builds and qualifies the analysis modules with the pinned Emscripten |
| `registry-nightly.yaml` | Registry nightly qualification | `17 2 * * *` (02:17 UTC) and manual dispatch | Five-voter Registry qualification and parser fuzzing |
| `runner.yaml` | Build Runner Image | Pushes to `master` that touch `docker/runner/**`, and dispatch | Publishes `baander-runner` |
| `postgres.yaml` | Build PostgreSQL Image | Pushes to `master` that touch `docker/postgres/**`, and dispatch | Publishes `baander-postgres` |
| `nginx.yaml` | Build Nginx Image | Pushes to `master` that touch `docker/nginx/**`, and dispatch | Publishes `baander-nginx` |
| `ffmpeg.yaml` | Build FFmpeg Image | Pushes to `master` that touch `packages/baander-aac/**`, `docker/ffmpeg/**` or the capability gate script, and dispatch | Publishes `ffmpeg-baander-static` |
| `aac-native.yml` | AAC native addon | Pushes, tags and pull requests that touch `packages/baander-aac/**` | GitHub only; see below |

A workflow with path filters lists its own file among them, so editing the
workflow runs it. The README's badges show GitHub's status for CI, Frontend CI,
Public website, DSP analysis qualification and Registry nightly qualification. The
image workflows run rarely or only on dispatch, so they have no badge.

## One tree on two forges

The shared tree lives in `.github/workflows` because neither forge reads more than
one directory. GitHub reads only `.github/workflows`. Forgejo reads the first
directory that exists among `.forgejo/workflows`, `.gitea/workflows` and
`.github/workflows`, and never merges them. Do not create either of the first two:
Forgejo would then run that directory and ignore every shared workflow.

The AAC native addon workflow is the one exception. It needs GitHub's hosted macOS
and Windows runners, so its jobs carry `if: github.server_url == 'https://github.com'`.
On Forgejo the same jobs resolve `runs-on` to `docker-cli` and skip there. Forgejo
evaluates a job's `if:` only after a matching runner picks the job up, so a macOS or
Windows label would leave the skipped job queued.

### Forge detection

Every per-forge value switches on one expression:

```yaml
github.server_url == 'https://github.com'
```

When it is true, the job takes GitHub's value; otherwise it takes Forgejo's. Shell
steps make the same test on `$GITHUB_SERVER_URL`.
Forgejo fills the `github.*` context with the same names as its own `forgejo.*`
context, so the workflows read `github.*` on both forges. `github.server_url` also
exists in the context from which Forgejo resolves job-level keys such as `runs-on`
when it creates a run, which is why the runner label can depend on it.
`github.forgejo_server_version` is missing from that context, so the workflows do
not use it.

The runner label never depends on a repository variable. A missing variable would
otherwise produce a label that no runner serves, and the job would wait in the queue
indefinitely. A fork pull request, which may not receive variables, therefore still
resolves its runner label.

### What differs per forge

| Concern | Forgejo | GitHub |
| --- | --- | --- |
| Runner label | `docker-cli` (self-hosted) | `ubuntu-latest` (hosted) |
| Job container | `<REGISTRY_URL>/<REGISTRY_OWNER>/baander-runner:latest` | `ghcr.io/baander-app/baander-runner:latest` |
| Registry and image owner | The `REGISTRY_URL` and `REGISTRY_OWNER` variables | `ghcr.io` and `baander-app` |
| Registry login | The `REGISTRY_TOKEN` secret | The workflow token (`github.token`) |
| Checkout credentials | `Authorization: token <workflow token>` | Basic authentication as `x-access-token` with the workflow token |
| Caches and coverage baselines | Forgejo generic packages through `scripts/ci-package-store.php` | `actions/cache` |
| Artifacts | `.github/actions/forgejo-upload-artifact` | `actions/upload-artifact` v7.0.2 |
| Host disk cleanup | None | Before the CI quality gate and the application build |
| Anonymous-pull check | None | After pushing the Nginx, FFmpeg and application images |

Registry login passes `secrets.REGISTRY_TOKEN || github.token` as the password, with
`github.actor` as the user. Job timeouts are the same on both forges.

The CI quality gate and the production build in `ci.yaml` need the disk that a
hosted runner's preinstalled toolchains occupy. On GitHub they first run a
short-lived container through the mounted Docker socket. It bind-mounts the host's
preinstalled .NET, Android, GHC and hosted-toolcache directories, deletes their
contents and reports the host's free space. A plain `run:` step would only reach
the job container. The step is GitHub-only, because on the self-hosted Forgejo host
it would delete real directories.

### Checkout

Every job in the shared workflows checks out with a shell script instead of
`actions/checkout`; only the GitHub-only AAC native addon workflow uses the action.
The script first marks the workspace as a safe directory in the container's global
Git configuration, as `actions/checkout` does. On GitHub the job container runs as
root while the hosted runner's user owns the workspace, and Git refuses a repository
owned by another user. Gate jobs fetch `github.event.pull_request.head.sha || github.sha`, check it out detached and
fail unless `HEAD` is that commit. On a GitHub pull request `github.sha` is a
temporary merge commit, while Forgejo's is the head commit; fetching the head commit
on both makes both forges qualify the same code. A branch that advances during the
run cannot replace the commit under qualification. The image workflows fetch
`github.ref` instead.

### Rules for editing workflows

`PublicationWorkflowTest` parses the shared tree and evaluates each condition once
for GitHub and once for Forgejo. It enforces the following rules, except the
`data.forgejo.org` check in the second, which you make by hand:

- Use `github.*` only. `forgejo.*` and `github.forgejo_server_version` do not exist
  on GitHub.
- Write every `uses:` in short form and pin it to a commit SHA. GitHub rejects
  full-URL `uses:` at job setup. Forgejo resolves short-form actions through
  `data.forgejo.org`, which mirrors the upstream SHAs; before pinning a new SHA,
  check that `git ls-remote https://data.forgejo.org/actions/<name>` lists it.
- Put a Forgejo-only action in a composite action under `.github/actions/forgejo-*`
  and call it only from a Forgejo-guarded step, so GitHub never resolves it.
- Give a step that differs by forge a twin, named `… on GitHub` and `… on Forgejo`,
  each guarded by the forge expression. The test checks the artifact, cache and
  baseline twins.
- Make `runs-on` resolve to a hosted label on GitHub and to `docker-cli` on Forgejo.
- Run `git config --global --add safe.directory "$PWD"` before a checkout script
  initialises the workspace.
- Give a step that sources `emsdk_env.sh` `shell: bash`. GitHub runs steps in a job
  container under `sh` by default, where the script cannot find its own directory
  and `emcc` never reaches `PATH`.
- Set `HOME: /root` on a container job that installs Playwright browsers. GitHub
  mounts a home directory owned by the runner's user into the root container, and
  Firefox refuses to start there.
- Make `timeout-minutes` resolve to a number of minutes on both forges. Both forges
  use the same timeouts; give a job a per-forge expression only after a GitHub run
  shows that it needs more time.
- Declare `concurrency` per workflow and ref, cancelling in progress only for
  `pull_request`. Forgejo cancels superseded runs by default and GitHub does not;
  with this setting neither forge cancels a `master` run once it has started.
  GitHub still holds one pending run per group, so a newer `master` push cancels
  an older `master` run that has not started yet. The newest `master` commit
  publishes; a commit whose run was cancelled before it started gets no image.
- Keep top-level `permissions` read-only. Only jobs that publish an image or save to
  the Forgejo package store request `packages: write`, because Forgejo may limit its
  workflow token to the job's `permissions`. Only commenting jobs request
  `pull-requests: write`.
- Guard every write step with the trusted-run condition below.
- Mount host paths only in GitHub-guarded steps.

## Trusted runs and fork pull requests

Pull requests from forks run every gate. They receive no secrets and, on GitHub, a
read-only workflow token, so every step that writes, publishes or comments carries
the trusted-run condition:

```yaml
github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository
```

The run is trusted when it is not a pull request, or when the pull request's head
branch lives in this repository. The condition guards registry login, cache and
baseline saves, the pull-request baseline restore, coverage comments and the
application image push. The image workflows never run for pull requests. In a fork
pull request those steps are skipped without failing the run, so the verdict comes
from the gates alone and no coverage comment appears.

A fork pull request on GitHub therefore pulls the runner and PostgreSQL images
without logging in. Those two packages must be public for its jobs to start.

## Caches and coverage baselines

Saves happen only on `master` pushes, and pull requests restore what `master`
saved. Each forge keeps its own copy:

| Data | Workflow | Forgejo package and file | GitHub cache key |
| --- | --- | --- | --- |
| BuildKit cache for the CI image | `ci.yaml` | `buildkit-cache-backend`, `buildkit-cache.tar.zst` | `buildkit-cache-backend-<sha>` |
| Yarn cache | `frontend.yaml` | `yarn-cache-frontend`, `yarn-cache.tar.zst` | `yarn-cache-frontend-<sha>` |
| Backend coverage baseline | `ci.yaml` | `coverage-backend`, `metrics.json` | `coverage-backend-<sha>` |
| Frontend coverage baseline | `frontend.yaml` | `coverage-frontend`, `metrics.json` | `coverage-frontend-<sha>` |

On GitHub every save creates a new key that ends in the commit SHA, and a restore
asks for the current commit's key with the key prefix (`buildkit-cache-backend-`
and so on) as its fallback. A pull request has no entry for its own commit, so it
receives the newest entry `master` saved under that prefix. GitHub evicts entries
when the repository's cache passes its size limit (10 GB) or an entry goes unused.
An evicted baseline makes the coverage comment show `—` in the baseline column,
the same result as a repository whose `master` has not stored one yet.

On Forgejo `scripts/ci-package-store.php` keeps each archive or baseline as one file
in version `latest` of a generic package owned by the repository's owner. The job
passes its workflow token as `FORGEJO_TOKEN`. A missing or unreadable package
restores an empty directory or fetches nothing, so a cold cache never fails a job.
A save replaces the previous file and fails the step when the upload fails; the
backend baseline store is allowed to fail without failing the job. Jobs that call
the script first install the standalone tooling with
`composer --working-dir=scripts install --no-dev --no-interaction --no-scripts --no-plugins`.

The coverage comment posts to `$GITHUB_API_URL/repos/$GITHUB_REPOSITORY/issues/<number>/comments`,
which both forges serve.

The CI image build reads the restored BuildKit layer cache from `/tmp/buildkit-cache`
and exports a new one to `/tmp/buildkit-cache-new`, which then replaces the restored
directory. The local cache exporter does not remove old blobs from its destination,
so exporting over the restored cache would grow it on every save; with the swap,
each save holds only what the latest build used. The build uses a named `docker-container`
builder on the job's own daemon so it can export that cache; the default `docker`
driver may refuse `--cache-to type=local`. The builder's BuildKit image is pinned by
version and digest.

## Artifacts

Both forges keep the same artifacts for 14 days:

| Artifact | Workflow | Kept |
| --- | --- | --- |
| `backend-verification` | `ci.yaml` | Always; `var/test-runs/`, `var/test-isolation/` and `coverage/` |
| `registry-packaged-inventory` | `ci.yaml` | Always; fails when the inventory is missing |
| `application-packaged-inventory-production` | `ci.yaml` | Always |
| `public-website-<sha>` | `website.yaml` | After a successful build; the archive and its SHA-256 sum |
| `registry-nightly-<run-id>-<attempt>` | `registry-nightly.yaml` | Always; the nightly evidence directory |

GitHub uploads with `actions/upload-artifact`. Forgejo uploads through the
`.github/actions/forgejo-upload-artifact` composite action, which wraps Forgejo's
fork of `upload-artifact`. The fork exists because upstream releases from v4 on
refuse any server other than github.com.

## Images and their visibility

| Image | Workflow | Forgejo | GitHub |
| --- | --- | --- | --- |
| `baander-runner` | `runner.yaml` | Private registry | Public on GHCR |
| `baander-postgres` | `postgres.yaml` | Private registry | Public on GHCR |
| `baander-nginx` | `nginx.yaml` | Private registry | Private on GHCR, checked after each push |
| `ffmpeg-baander-static` | `ffmpeg.yaml` | Private registry | Private on GHCR, checked after each push |
| `baander` | `ci.yaml`, `build-and-push` job | Private registry | Private on GHCR, checked after each push |

Each push tags the image `latest` and with the commit SHA. On GitHub the images are
`ghcr.io/baander-app/<name>`.

The application image copies `ffmpeg` and `ffprobe` from `ffmpeg-baander-static`
through the `ffmpeg` role of the tooling image catalog
(`packages/baander-tooling/resources/tooling-defaults.json`), passed to the build as
`FFMPEG_IMAGE`. The role names the GHCR image. On Forgejo, `ci.yaml` sets
`BAANDER_FFMPEG_IMAGE` to the copy in the Forgejo registry, which `ffmpeg.yaml`
publishes there; a developer host sets the same variable to a local build. Because
the GHCR image is private, the application image cannot be built by a pull request
from a fork, which has no registry login. The FFmpeg image carries bAAC from
`packages/baander-aac`, fdk-aac and FFmpeg's built-in AAC codec, enables VAAPI,
NVENC and QSV explicitly, and proves itself at build time: a smoke encodes and
decodes with bAAC, and `scripts/ffmpeg-capability-gate.sh` checks that the binary
lists every accelerator, hardware encoder and AAC implementation the application can
select, and that its shared objects resolve. The same gate runs when the application
image is built.

The runner and PostgreSQL images are CI tooling and may be public. The application
image keeps notice, provenance and embedded-dependency gaps that the
[application release acceptance](../../ROADMAP.md#application-release-acceptance)
requires closing before redistribution, and the Nginx and FFmpeg images stay private
with it.

The runner workflow builds inside the public `docker:cli` image. Every other job
runs inside `baander-runner`, and the CI quality gate pulls `baander-postgres`, so
both images must exist in a forge's registry before that forge's first CI run.

`build-and-push` runs on pull requests too: it builds the production image and
verifies its packaged inventory, but pushes only from `master`.

### The anonymous-pull check

GHCR creates every new package as private, and GitHub documents visibility changes
only in its web interface, so an operator makes each change by hand. A package can
therefore become public by mistake. After each push of the Nginx, FFmpeg or
application image, the job runs `scripts/ghcr-private-probe.sh`. The script runs
`docker manifest inspect` for the image's `latest` tag with an empty Docker
configuration, so no stored login takes part:

- If the registry serves the manifest, anyone can pull the image. The job fails
  with "publicly pullable".
- If the registry refuses (denied, unauthorized, or an unknown manifest or name),
  the image stays private and the step passes.
- Any other error, such as a network failure, also fails the job with "Cannot
  confirm", because privacy is unconfirmed.

The check runs after the push, so a failure means the image is already public.

## Staged rollout

GitHub runs `workflow_dispatch` only for workflows on the default branch, and every
GitHub gate job runs inside the GHCR runner image. GitHub therefore comes up in two
stages: the image workflows reach `master` first and publish the runner and
PostgreSQL images, which an operator makes public; then the gate workflows follow,
and their first runs find both images. The GitHub runbook below lists the steps.

Forgejo's registry already holds every image, so Forgejo needs no staging.

## Operator runbook

### Forgejo

- Confirm the repository variables `REGISTRY_URL` (the registry host) and
  `REGISTRY_OWNER` (the image owner), and the secret `REGISTRY_TOKEN` (a token that
  can push to that registry). The shared workflows read the same variables and
  secrets as the earlier Forgejo-only workflows, so none needs removing.
- `BAANDER_TEST_DOCKER_SOCKET_PATH` is optional. The CI quality gate defaults it to
  `/var/run/docker.sock`; set it for a runner whose daemon socket lives elsewhere.
- Keep a runner registered with the `docker-cli` label.

### GitHub settings

Keep these settings in place:

- **Package creation.** The organization's package-creation setting allows public
  packages. GHCR still creates every new package as private.
- **Workflow token.** The repository's default workflow token permissions stay
  read-only. Publishing jobs request `packages: write` in their own `permissions`
  block, and commenting jobs request `pull-requests: write`.
- **No registry secret.** The repository defines no `REGISTRY_TOKEN` secret. Login
  uses `secrets.REGISTRY_TOKEN || github.token`, so such a secret would replace the
  workflow token.

### First bring-up on GitHub

1. **Publish the runner image.** Push the image workflows to `master`; adding
   `runner.yaml` starts it. Otherwise dispatch it with
   `gh workflow run runner.yaml --repo baander-app/baander`.
2. **Make the runner image public.** Open the `baander-runner` package under the
   `baander-app` organization, change its visibility to public and connect it to
   the `baander-app/baander` repository if it is not connected yet.
3. **Publish the PostgreSQL image.** The PostgreSQL workflow runs inside the runner
   image. If its first run started before `baander-runner` was published and public,
   the job could not start its container; dispatch it again with
   `gh workflow run postgres.yaml --repo baander-app/baander`.
4. **Make the PostgreSQL image public.** Change `baander-postgres` the same way as
   the runner image in step 2.
5. **Publish the Nginx image.** The Nginx workflow also runs inside the runner image.
   If its first run failed for the same reason, dispatch `nginx.yaml` again. The
   image stays private.
6. **Publish the FFmpeg image.** The FFmpeg workflow never runs on push. Dispatch it
   once with `gh workflow run ffmpeg.yaml --repo baander-app/baander` to create its
   package. The image stays private.
7. **Push the gate workflows.** Their first runs pull both public images.

### When GitHub needs attention

- **Failed anonymous-pull check.** When the log says the image is publicly pullable,
  make that package private at once in its package settings, then re-run the job to
  confirm. A "Cannot confirm" failure is an error during the check; read the
  printed output before acting.
- **Suspended nightly.** GitHub disables scheduled workflows in a public repository
  after 60 days without repository activity. Re-enable Registry nightly
  qualification on the repository's Actions page, or run
  `gh workflow enable registry-nightly.yaml --repo baander-app/baander`.

## See also

- [Testing](testing.md#application-publication-gates) — the gates CI runs and the
  workflow contract test.
- [Registry nightly qualification](../../relay/README.md#nightly-qualification-and-retained-diagnostics)
  — the nightly recipe and its retained evidence.
