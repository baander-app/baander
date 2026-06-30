---
date: 2026-05-10
topic: forgejo-ci-pipeline
---

# Forgejo Actions CI Pipeline

## Summary

Two Forgejo Actions pipelines: one builds and pushes the FFmpeg static base image, the other runs the existing quality gate (composer checks, phpstan, deptrac, phpunit) then builds the production Docker image and pushes it to Forgejo's built-in registry. Dependency caching for both composer and Docker layers is included. No deployment.

---

## Problem Frame

The project has a Forgejo runner available with Docker socket access, but no CI pipeline is configured. Quality checks (`make ci`) run only locally, and production Docker images are built manually. This means broken builds or failing static analysis can reach the main branch undetected, and there is no reproducible, versioned image artifact ready for future deployment.

---

## Actors

- A1. **Developer**: Pushes code or opens PRs, reads pipeline results
- A2. **Forgejo Runner**: Executes pipeline workflows, has Docker socket mounted
- A3. **Forgejo Container Registry**: Stores pushed Docker images

---

## Key Flows

- F1. **FFmpeg image build**
  - **Trigger:** Changes to `docker/ffmpeg/` directory, or manual dispatch
  - **Actors:** A2, A3
  - **Steps:** Checkout repo, build FFmpeg static image, push to Forgejo registry
  - **Outcome:** Updated `ffmpeg-baander-static` image available in the registry

- F2. **App quality gate (PR)**
  - **Trigger:** Pull request opened or updated targeting master
  - **Actors:** A1, A2
  - **Steps:** Checkout repo, restore dependency cache, start service containers (PostgreSQL, Redis), run `make ci` quality checks, report results
  - **Outcome:** PR shows passing or failing quality gate

- F3. **App quality gate + image build (master push)**
  - **Trigger:** Push to master
  - **Actors:** A2, A3
  - **Steps:** Checkout repo, restore dependency cache, start service containers, run quality checks, build production Docker image, push image to Forgejo registry tagged with git SHA
  - **Outcome:** Quality gate passed, versioned production image in registry
  - **Covered by:** R1, R2, R3, R4, R5

---

## Requirements

**Pipeline infrastructure**

- R1. Two separate workflow definitions: one for the FFmpeg image, one for the main app
- R2. Both workflows run on the Forgejo runner with Docker socket access
- R3. Dependency caching covers composer dependencies and Docker build layers
- R4. Cached dependencies are restored at the start of each run and updated after install/build

**FFmpeg pipeline**

- R5. Triggers on changes to `docker/ffmpeg/` and supports manual dispatch
- R6. Builds the FFmpeg static image and pushes it to the Forgejo registry

**App pipeline**

- R7. Triggers on pull requests and pushes to master
- R8. Spins up PostgreSQL and Redis as service containers before running checks
- R9. Runs the full quality gate: composer validate, composer normalize --dry-run, phpstan, deptrac, phpunit
- R10. Builds the production Docker image (using the multi-stage Dockerfile's `production` target)
- R11. Pushes the production image to the Forgejo registry on master pushes only, tagged with the commit SHA
- R12. On PRs, runs the build to verify it succeeds but does not push the image

---

## Acceptance Examples

- AE1. **Covers R7, R9, R12.** Given a PR is opened against master, when the pipeline runs, the quality gate executes and the production image builds, but no image is pushed to the registry.
- AE2. **Covers R7, R9, R10, R11.** Given a commit is pushed directly to master, when the pipeline runs, the quality gate passes, the production image builds, and the image is pushed to the registry tagged with the commit SHA.
- AE3. **Covers R5, R6.** Given a change is pushed to `docker/ffmpeg/Dockerfile`, when the FFmpeg pipeline runs, the image builds and is pushed to the registry.
- AE4. **Covers R3, R4.** Given a second pipeline run after the first, when composer install runs, cached dependencies are restored and the install step completes faster than the first run.

---

## Success Criteria

- Every push to master and every PR against master triggers the quality gate automatically
- A failing phpstan, deptrac, or phpunit check blocks the PR from being mergeable
- A versioned production Docker image exists in the Forgejo registry after every master push
- Pipeline runs with warm cache complete meaningfully faster than cold runs
- The FFmpeg base image can be rebuilt and pushed without manual Docker commands

---

## Scope Boundaries

- Deployment to staging or production environments
- Branch protection rules or merge gating configuration in Forgejo
- Pipeline failure notifications (email, Slack, webhooks)
- Frontend/Node.js linting or test verification beyond what the production image build covers
- Multi-architecture builds (arm64, etc.)
- Secrets management beyond Forgejo's built-in secrets

---

## Key Decisions

- **Two separate workflows rather than one:** The FFmpeg image changes rarely and has no quality gate — combining it with the app pipeline would add unnecessary coupling and runner time
- **Push on master only, not PRs:** PR images are ephemeral and would pollute the registry; the build verification step is sufficient for PRs
- **Forgejo's built-in registry over external:** Keeps everything in one place, no external credentials or Docker Hub rate limits
- **git SHA tagging:** Simplest reliable tag strategy; semver or branch-name tagging can be added later

---

## Dependencies / Assumptions

- The Forgejo runner has the Docker socket mounted and can execute `docker compose` and `docker build`
- The Forgejo instance has the container registry feature enabled
- The FFmpeg static image will be pushed to the same Forgejo registry and referenced by the app Dockerfile via its registry URL
- PostgreSQL and Redis can be started as service containers or via docker-compose within the pipeline

---

## Outstanding Questions

### Resolve Before Planning

(none)

### Deferred to Planning

- [Affects R3][Technical] How to implement Docker layer caching with Forgejo Actions — volume mounts, registry cache, or BuildKit cache export
- [Affects R5][Technical] Whether Forgejo Actions supports `on: push paths:` filtering or if path filtering needs to be inline in the workflow
- [Affects R11][Technical] Exact registry URL format and authentication method for Forgejo's built-in registry
