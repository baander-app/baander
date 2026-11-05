---
title: Run one workflow tree on Forgejo and GitHub without silent skips
date: "2026-11-05"
last_updated: "2026-11-05"
category: developer-experience
module: CI workflows shared by Forgejo and GitHub
problem_type: developer_experience
component: development_workflow
related_components: [infrastructure, tooling]
severity: high
applies_when:
  - Adding or moving a workflow file, or creating a .forgejo or .gitea directory
  - Choosing a per-forge value for runs-on, a registry, a cache or an artifact action
  - Changing how build-application.php selects a buildx builder or exports BuildKit cache
  - Changing the anonymous-pull probe for private GHCR images
  - Adding a step to a container job that starts a browser, sources an environment script or pulls an image published by the same push
retire_when: "Forgejo merges workflow directories or exposes forgejo_server_version when it resolves runs-on, or GitHub's docker buildx driver accepts --cache-to type=local; check the Forgejo release notes and the docker/buildx driver documentation"
tags: [forgejo, github-actions, workflows, buildkit, buildx, ghcr, ci, forge]
---

# Run one workflow tree on Forgejo and GitHub without silent skips

## Context

Baander's CI ran only on the self-hosted Forgejo instance, from `.forgejo/workflows`
(removed by this port). Porting it to GitHub as one shared tree, `.github/workflows`,
turned up several forge behaviours that do not raise an error. When one of them goes wrong, a workflow
stops running, a job waits in the queue forever, a cache never restores, or a check
passes for the wrong reason. The
[CI guide](../../../docs-book/part-2-developer-guide/continuous-integration.md)
states the resulting rules. This note records the traps behind them and the dead
ends hit on the way, which neither the guide nor the code explains.

## Guidance

**Keep exactly one workflow directory.** Forgejo 15.0.11 reads the first directory
that exists among `.forgejo/workflows`, `.gitea/workflows` and `.github/workflows`
and never merges them (per the Forgejo source read during this work). The port
therefore deleted `.forgejo/workflows`. A future `.forgejo/workflows/foo.yaml`, even
a single file, makes Forgejo ignore every shared workflow without any warning.
`PublicationWorkflowTest` asserts the tree layout.

**Detect the forge with `github.server_url == 'https://github.com'`.** The plan first
used `github.forgejo_server_version`, which looks like the natural test. Forgejo
lacks that key in the context it uses to resolve `runs-on` when it creates a run, so
the label would always fall to the GitHub branch. Per-forge values follow the shape
at `.github/workflows/ci.yaml:27`:

```yaml
runs-on: ${{ github.server_url == 'https://github.com' && 'ubuntu-latest' || 'docker-cli' }}
```

Never let a runner label depend on a repository variable. When the variable is
missing, the label matches no runner and the job waits in the queue with no error.
For the same reason a GitHub-only job (the AAC addon) still resolves to `docker-cli`
on Forgejo and skips through its `if:`. Forgejo evaluates `if:` only after a runner
has picked up the job.

**Trust the workspace before a shell checkout touches it.** The shell checkout that
works on Forgejo failed on the first GitHub run with
`fatal: detected dubious ownership in repository at '/__w/baander/baander'`. GitHub
mounts a workspace owned by the hosted runner's user into a job container that runs
as root, and Git refuses a repository that another user owns. Every checkout now
runs `git config --global --add safe.directory "$PWD"` before `git init -q`, as
`actions/checkout` does. The setting is global because later steps in the job also
run Git. `PublicationWorkflowTest` rejects a workspace `git init` without it and
runs the checkout scripts with a throwaway `HOME`, so the test never writes to a
developer's own Git configuration. The two traps below come from the same job
container: GitHub gives it a different shell and a different home directory from
the ones Forgejo's runner image gave the same steps, and nothing in the workflow
files shows the difference.

**Give a step that sources `emsdk_env.sh` `shell: bash`.** On GitHub a `run:` step
inside a job container executes under `sh -e` unless the step names a shell. The
first GitHub run installed Emscripten and then failed the next step with
`Error: unable to determine 'emsdk' directory` and `emcc: command not found` (exit
127): `emsdk_env.sh` finds its own directory only under bash, so `emcc` never reached
`PATH`. The DSP steps set `shell: bash` (`.github/workflows/ci.yaml:622`,
`.github/workflows/dsp-analysis.yaml:98`), and `PublicationWorkflowTest` rejects a
step that sources `emsdk_env.sh` without it
(`tests/Unit/Shared/Infrastructure/Ci/PublicationWorkflowTest.php:1106`).

**Set `HOME: /root` on container jobs that start Firefox.** GitHub mounts
`/github/home`, owned by the hosted runner's user, as the root container's home
directory, the same ownership split as the workspace. Playwright's Firefox refuses
to start there: "Firefox is unable to launch if the $HOME folder isn't owned by the
current user". Chromium and WebKit start, so the website job passed while the two
jobs that start Firefox inside the job container failed; the backend gate's Firefox
step is unaffected because it runs its browser in the Playwright image. A job-level
`env` sets `HOME: /root`
(`.github/workflows/ci.yaml:542`, `.github/workflows/frontend.yaml:136`,
`.github/workflows/website.yaml:61`), and the contract test requires it on every
container job that installs Playwright browsers
(`tests/Unit/Shared/Infrastructure/Ci/PublicationWorkflowTest.php:1071`), so the
exposure does not depend on which browsers a job happens to use today.

**Expect the first image push to fail, and make the runner package public by hand.**
The image workflows of one push run in parallel with the runner build, and each of
them runs inside the runner image, so on the first push the PostgreSQL and Nginx runs
failed with `manifest unknown` and passed when re-run after the runner image was
published. A gate job that declares neither `packages: read` nor `packages: write`
could not pull the private runner image at all: GitHub pulls the job container with
the job's own token before any step runs, so the pull was refused with `denied`,
while the jobs that declared `packages: write` for the package store pulled it. A `docker login` step runs too
late to help. That, not convenience, is why the runbook makes the runner package
public. GitHub has no API for changing a container package's visibility: `gh api
/orgs/baander-app/packages/container/baander-runner --jq .visibility` only reads it,
and the package settings page does not save the change until the package name is
typed into its confirmation dialog, which cost one extra round here. An anonymous
`docker manifest inspect` with an empty `DOCKER_CONFIG` confirms the result.

**Select the buildx builder through the build tool, not the workflow.** GitHub's
hosted Docker uses the `docker` driver, which may refuse `--cache-to type=local`, so the
CI image build needs a `docker-container` builder. The first plan added a
`docker buildx create --use` step, which looks sufficient. It was not:
`Docker\Client` appended `--builder default` to every buildx call that named no
builder, so the build kept running on the default builder and the step had no
effect. The fix passes the builder explicitly (`--builder "$CI_BUILDER"`,
`.github/workflows/ci.yaml:123`), and `Client::withBuilder()` accepts it only when
`docker buildx inspect` shows the `docker-container` driver with every node on the
captured daemon (`packages/baander-tooling/src/Docker/Client.php:43`). That keeps the
rule that no builder name can move a build to another engine.

**Name the builder per run and remove it in an `always()` step.** A fixed name such
as `baander-ci` collides on Forgejo, where concurrent jobs share one Docker daemon:
one job's cleanup removes another job's builder, and a cancelled job leaves a BuildKit
container behind. The workflow uses
`baander-ci-${{ github.run_id }}-${{ github.run_attempt }}` (`ci.yaml:30`) and runs
`docker buildx rm` at job end (`ci.yaml:533`).

**Archive caches relative to their directory.** The old Forgejo save step ran
`tar -cf - -C / tmp/buildkit-cache`, and the restore step extracted into
`/tmp/buildkit-cache`. The restore therefore produced
`/tmp/buildkit-cache/tmp/buildkit-cache` and BuildKit never found its cache, yet
every step still passed because the extract ended in `|| true`. `PackageStore`, which
`scripts/ci-package-store.php` drives, archives with `--directory <dir> .` so save and
restore are symmetric. The build also exports to a fresh `/tmp/buildkit-cache-new`
and swaps it in (`ci.yaml:126`, `ci.yaml:134`), because a local cache exported over
its own source only grows.

**Make the privacy probe fail closed, and test "permission denied" first.**
`scripts/ghcr-private-probe.sh` treats a registry refusal as proof that the image is
private, and it matches the refusal on substrings such as `denied`. A runner that
cannot reach the Docker socket also prints "permission denied", which contains
`denied`. The probe therefore checks `*"permission denied"*` before the refusal
patterns and sends it to the failing branch. Without that ordering, a broken runner
would report every image as private.

**Stage the first GitHub rollout.** GitHub runs `workflow_dispatch` only for
workflows on the default branch, and the gate jobs pull the runner image from GHCR.
The image workflows must reach `master` and publish first, an operator must make the
runner and PostgreSQL packages public, and only then can the gate workflows land.
The guide's runbook lists the order.

## Why This Matters

Most of these traps fail silently: a skipped workflow, a job that waits in the queue,
a step that has no effect, a cache that never restores, a false pass. The first
GitHub runs failed loudly on three of them, the checkout ownership, the `sh` default
shell and the home-directory ownership, and the forge-neutral port caught the rest
through review and by reading the Forgejo source, not through a red run.
A regression shows up as CI that is slower, missing or wrongly green, and nothing
points back to these causes.

## When to Apply

- Before adding any workflow file or forge-specific directory.
- Before adding a per-forge expression, especially in `runs-on`.
- When touching builder selection in `packages/baander-tooling/src/Docker/Client.php`
  or the BuildKit cache steps in `ci.yaml`.
- When editing the patterns in `scripts/ghcr-private-probe.sh`.
- When adding a step to a container job that starts a browser, sources an
  environment script or pulls an image that the same push publishes.

## Examples

Workflow-level builder selection that the build tool silently overrode:

```yaml
- run: docker buildx create --name baander-ci --driver docker-container --use
- run: php scripts/build-application.php --buildx --cache-to type=local,dest=/tmp/buildkit-cache
```

Explicit, same-daemon builder that the build tool verifies:

```yaml
- run: docker buildx create --name "$CI_BUILDER" --driver docker-container ...
- run: php scripts/build-application.php --buildx --builder "$CI_BUILDER" \
         --cache-to type=local,dest=/tmp/buildkit-cache-new,mode=max \
         --cache-from type=local,src=/tmp/buildkit-cache
```

## Related

- [CI guide and operator runbook](../../../docs-book/part-2-developer-guide/continuous-integration.md)
- [Local gate images](local-gate-images-and-webkit-harness-runs.md): rebuilds the CI
  image with the same `build-application.php`. Locally no `--builder` is needed.
