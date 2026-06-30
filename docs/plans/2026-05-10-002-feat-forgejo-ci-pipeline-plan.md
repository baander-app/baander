---
title: "feat: Forgejo Actions CI Pipeline"
type: feat
status: active
date: 2026-05-10
origin: docs/brainstorms/forgejo-pipeline-requirements.md
---

# feat: Forgejo Actions CI Pipeline

## Summary

Two Forgejo Actions workflow files — one for the FFmpeg static base image, one for the Baander app. The app pipeline runs each quality gate check as a separate step (not via `make ci`), builds the production Docker image, and pushes to the Forgejo registry on master. Caching for both composer and Docker layers via registry-based buildx cache.

---

## Problem Frame

The project has a Forgejo runner with Docker socket access but no CI pipeline. Quality checks run only locally, and production images are built manually. Broken builds and failing static analysis can reach master undetected, and there is no versioned image artifact ready for future deployment.

---

## Requirements

- R1. Two separate workflow definitions: one for FFmpeg, one for the app
- R2. Both workflows run on the Forgejo runner with Docker socket access
- R3. Dependency caching covers composer dependencies and Docker build layers
- R4. Cached dependencies are restored at the start of each run and updated after install/build
- R5. FFmpeg pipeline triggers on changes to `docker/ffmpeg/` and supports manual dispatch
- R6. FFmpeg pipeline builds and pushes the image to the Forgejo registry
- R7. App pipeline triggers on PRs and pushes to master
- R8. App pipeline spins up PostgreSQL and Redis as service containers before running checks
- R9. App pipeline runs each quality check as a separate step: composer validate, composer normalize --dry-run, phpstan, deptrac, phpunit
- R10. App pipeline builds the production Docker image (multi-stage Dockerfile `production` target)
- R11. App pipeline pushes the production image to the Forgejo registry on master pushes only, tagged with the commit SHA
- R12. On PRs, the build runs to verify it succeeds but does not push the image

**Origin actors:** A1 (Developer), A2 (Forgejo Runner), A3 (Forgejo Container Registry)
**Origin flows:** F1 (FFmpeg build), F2 (PR quality gate), F3 (master quality gate + push)
**Origin acceptance examples:** AE1 (PR no-push), AE2 (master push+tag), AE3 (FFmpeg trigger), AE4 (cache warm run faster)

---

## Scope Boundaries

- Deployment to staging or production environments
- Branch protection rules or merge gating configuration in Forgejo
- Pipeline failure notifications (email, Slack, webhooks)
- Frontend/Node.js linting or test verification beyond the production image build
- Multi-architecture builds (arm64, etc.)
- Secrets management beyond Forgejo's built-in secrets
- Changes to existing Makefile targets or application code

---

## Context & Research

### Relevant Code and Patterns

- `Dockerfile` — multi-stage, `ARG FFMPEG_IMAGE=martinjuul/ffmpeg-baander-static:latest` overrideable at build time, `production` target at line ~481
- `docker/ffmpeg/Dockerfile` — builds FFmpeg 8.0 with libs via ffmpeg-build-script, outputs `ffmpeg` and `ffprobe` binaries
- `docker-compose.yml` — defines app, nginx, database (PostgreSQL), Redis services; app container uses `privileged: true` with Docker socket
- `Makefile` — `make ci` chains composer validate, composer normalize, phpstan, deptrac, phpunit; `make build-prod` builds production target
- `composer.json` — PHP 8.5, Symfony with Swoole, Doctrine, Redis

### External References

- Forgejo Actions workflow syntax: `on: push: paths:`, `on: pull_request:`, service containers, conditional steps via `if: forgejo.ref`
- Forgejo registry URL format: `{forgejo-domain}/{owner}/{image}:{tag}`
- Registry auth: PAT with `package:write` scope required (automatic token may not work for OCI push — Codeberg issue #1296)
- Docker layer caching: `docker buildx --cache-from type=registry --cache-to type=registry` against Forgejo registry
- Composer caching: `actions/cache` with `key: php-composer-${{ hashFiles('composer.lock') }}`

---

## Key Technical Decisions

- **Fine-grained steps instead of `make ci`:** Each quality check runs as a separate workflow step for better failure visibility and per-step caching control (user direction)
- **Forgejo service containers for PostgreSQL/Redis:** Native support, no need to bring up docker-compose; services share the job network, accessible by hostname
- **Registry-based Docker layer cache:** `docker buildx --cache-from/--cache-to` against the Forgejo registry — no separate cache backend needed, cache co-located with images
- **PAT secret for registry push:** Store a personal access token with `package:write` scope as `REGISTRY_TOKEN` repository secret; `docker/login-action` for authentication
- **FFmpeg image build arg override:** The Dockerfile's `ARG FFMPEG_IMAGE` default stays unchanged; the CI pipeline passes `--build-arg FFMPEG_IMAGE=<forgejo-registry-url>/ffmpeg-baander-static:latest` to point at the registry copy
- **No application code changes:** The pipelines are pure infrastructure — no modifications to Dockerfiles, Makefile, or PHP code

---

## Open Questions

### Resolved During Planning

- Docker layer caching approach: registry-based buildx cache (not volume mounts or separate cache backend)
- Path filtering: Forgejo Actions supports `on: push: paths:` natively
- Registry auth: PAT with `package:write` scope as repository secret

### Deferred to Implementation

- Exact Forgejo instance domain and repository owner — needed to construct registry URLs and image names
- Runner label name — depends on the runner's `config.yaml` labels (visible in Forgejo UI at `/{owner}/{repo}/settings/actions/runners`)
- Whether the job container needs Docker CLI installed or if it's already present in the runner's default container image
- **Job container image for quality gate (U2):** composer.json requires PHP 8.5 with ext-swoole, ext-redis, ext-pgsql, ext-inotify, ext-sockets, and many others. No public PHP 8.5 image includes these. The project's own Dockerfile compiles Swoole from source with liburing. Options: (a) build the `runtime` or `dev` Docker stage first and use that image as the job container, (b) pre-build and push a CI-specific image, or (c) use `docker run` from the built image instead of the job's container. This is the single largest implementation decision.
- **Service container hostnames:** .env.test uses `database` and `redis` as hostnames. Forgejo service container names must match these exactly, or environment variables must be overridden in the phpunit step.
- **PGroonga extension:** The project's PostgreSQL image includes PGroonga 4.0.5. The standard postgres:18 service image will not have it. Verify whether any tests depend on PGroonga, or use the project's custom PostgreSQL image.
- **Database migrations in CI:** Functional/integration tests likely need a migrated database. No CI step currently runs Doctrine migrations against the service container PostgreSQL.
- **Bootstrap sequence:** The first-ever app pipeline run will fail unless the FFmpeg pipeline is manually dispatched first to seed the registry. Document this as a one-time setup step.

---

## Implementation Units

### U1. FFmpeg pipeline workflow

**Goal:** Build and push the FFmpeg static base image to the Forgejo registry when `docker/ffmpeg/` changes or on manual dispatch.

**Requirements:** R1, R2, R5, R6

**Dependencies:** None (can run independently)

**Files:**
- Create: `.forgejo/workflows/ffmpeg.yaml`

**Approach:**
- Trigger on push to `docker/ffmpeg/` path and on `workflow_dispatch`
- Checkout repo, build FFmpeg image from `docker/ffmpeg/Dockerfile`
- Tag as `{forgejo-domain}/{owner}/ffmpeg-baander-static:latest` and `{forgejo-domain}/{owner}/ffmpeg-baander-static:<short-sha>`
- Push to Forgejo registry using `docker/login-action` with `REGISTRY_TOKEN` secret
- Use `docker buildx` with `--cache-from/--cache-to` against the registry for layer caching

**Patterns to follow:**
- Forgejo Actions YAML syntax with fully-qualified action URLs (`https://data.forgejo.org/...`)
- `FORGEJO_OUTPUT` for step outputs, `forgejo.sha` for commit SHA

**Test scenarios:**
- Test expectation: none — pipeline workflow file. Verify by pushing a change to `docker/ffmpeg/` and confirming the image appears in the Forgejo registry.

**Verification:**
- FFmpeg image appears in Forgejo container registry after push to `docker/ffmpeg/`
- Manual dispatch triggers the workflow from the Actions tab

---

### U2. App CI pipeline — quality gate

**Goal:** Run each quality check as a separate step with PostgreSQL and Redis service containers and composer caching.

**Requirements:** R1, R2, R3, R4, R7, R8, R9

**Dependencies:** None (quality gate is independent of image build)

**Files:**
- Create: `.forgejo/workflows/ci.yaml`

**Approach:**
- Trigger on `push: branches: [master]` and `pull_request` targeting master
- Define PostgreSQL 18 and Redis (redis-stack-server) as service containers with health checks
- Steps run sequentially:
  1. Checkout repo
  2. Restore composer cache (`actions/cache` keyed on `composer.lock` hash)
  3. `composer install`
  4. `composer validate --no-check-version`
  5. `composer normalize --dry-run`
  6. `phpstan analyse --memory-limit=512M --no-progress` (XDEBUG_MODE=off)
  7. `deptrac analyse --no-cache --no-progress`
  8. `phpunit` with coverage and JUnit output (XDEBUG_MODE=off)
- Each step fails independently — Forgejo reports which specific check broke
- All steps run inside a container image that has PHP 8.5 CLI, or install PHP dependencies in the job

**Patterns to follow:**
- Separate steps per check for failure isolation
- `actions/cache` with `restore-keys` for cache fallback
- `XDEBUG_MODE=off` for static analysis and test steps (consistent with `make ci`)

**Test scenarios:**
- Test expectation: none — pipeline workflow file. Verify by opening a PR and confirming each quality check appears as a separate step with pass/fail status.

**Verification:**
- PR shows individual step results for each quality check
- Composer cache restores on subsequent runs (faster install step)

---

### U3. App CI pipeline — image build and push

**Goal:** Build the production Docker image after the quality gate passes, and push to the Forgejo registry on master only.

**Requirements:** R3, R4, R10, R11, R12

**Dependencies:** U2 (quality gate must pass before image build)

**Approach:**
- Runs after quality gate steps succeed (same job, sequential)
- Build production image with `docker buildx build --target production`
- Override `FFMPEG_IMAGE` build arg to point at the Forgejo registry copy
- Use `--cache-from/--cache-to` against the registry for Docker layer caching
- Conditional push: `if: forgejo.ref == 'refs/heads/master'` — login and push with SHA tag
- On PRs, the build completes but the push step is skipped

**Patterns to follow:**
- `docker/login-action` with `REGISTRY_TOKEN` secret (same as FFmpeg pipeline)
- `forgejo.sha` for image tagging
- `--cache-from type=registry,ref={forgejo-domain}/{owner}/baander:buildcache` / `--cache-to type=registry,ref={forgejo-domain}/{owner}/baander:buildcache,mode=max`

**Test scenarios:**
- Test expectation: none — pipeline workflow file. Verify by pushing to master and confirming the image appears in the registry tagged with the commit SHA.

**Verification:**
- Production image tagged with commit SHA appears in Forgejo registry after master push
- PR builds complete without pushing an image
- Warm cache builds are faster than cold builds

---

## Risks & Dependencies

| Risk | Mitigation |
|------|------------|
| No public PHP 8.5 image with Swoole + required extensions exists | Build the project's `runtime` stage first and use it as the job container; or pre-build a CI image |
| Service container hostnames must match .env.test (`database`, `redis`) | Name Forgejo service containers `database` and `redis` exactly, or override env vars |
| PGroonga extension absent from standard postgres:18 image | Use project's custom PostgreSQL image as service container, or verify no tests need PGroonga |
| Functional tests need migrated database | Add a Doctrine migrations step before phpunit |
| Runner container may lack Docker CLI | Verify Docker CLI availability in the job container; install if needed as a preparatory step |
| Forgejo automatic token may not work for OCI push | Use PAT with `package:write` scope as `REGISTRY_TOKEN` secret |
| FFmpeg image must exist before app image builds | FFmpeg pipeline runs first; `FFMPEG_IMAGE` build arg must reference the registry copy, not Docker Hub |
| First app pipeline run fails if FFmpeg image not seeded | Manually dispatch FFmpeg pipeline once before first app pipeline run (one-time setup) |
| Service container networking depends on runner's `container.network` being empty | Verify runner config; Forgejo default should work |
| Long build times on cold cache | First run will be slow; subsequent runs benefit from composer + Docker layer cache |

---

## System-Wide Impact

- **Interaction graph:** No existing application code is modified. Two new workflow files are added. The FFmpeg pipeline must run and push the image before the app pipeline can successfully build the production image (the `COPY --from=ffmpeg` stage requires the image to exist).
- **Unchanged invariants:** All existing `make` targets, Dockerfile, docker-compose.yml, and application code remain untouched.

---

## Sources & References

- **Origin document:** [docs/brainstorms/forgejo-pipeline-requirements.md](docs/brainstorms/forgejo-pipeline-requirements.md)
- Forgejo Actions reference: https://forgejo.org/docs/next/user/actions/reference/
- Forgejo Docker access: https://forgejo.org/docs/next/admin/actions/docker-access/
- Forgejo container registry: https://forgejo.org/docs/next/user/packages/container/
- Codeberg issue #1296 (OCI push token limitation): https://codeberg.org/forgejo/forgejo/issues/1296
