# Testing

Choose a runner that reaches the behavior you changed: domain logic, persistence,
background work, native encoding or a browser flow. Start with the quick commands
below, then read the subsystem guide for prerequisites and the limits of its
results. Historical qualification results describe earlier runs; they do not
replace running the checks for your change.

## Test Suites

| Suite | Directory | Scope |
|-------|-----------|-------|
| **Unit** | `tests/Unit/`, `packages/baander-http-client/tests/`, `packages/baander-image-processing/tests/`, `packages/baander-media-metadata/tests/`, `packages/baander-redis-rate-limiter/tests/` | Domain behavior, package mechanisms and application/infrastructure contracts; no Symfony kernel boot |
| **StaticAnalysisRules** | `packages/baander-phpstan-rules/tests/` | Custom PHPStan rules, including parsed-source request-payload fixtures |
| **Functional** | `tests/Functional/` | HTTP controllers, console commands, and application flows with Symfony kernel, PostgreSQL, and Redis |
| **Integration** | `tests/Integration/` | Database schema, firewall matrices, transport delivery, and concurrency contracts against disposable services |
| **Tooling** | `packages/baander-*/tests/`, `tests/Tooling/` | Standalone build, deployment, website, and test-runner orchestration contracts (`phpunit-tooling.xml.dist`) |

## Quick Start

Generate the standalone tooling autoloaders (`composer tooling:autoload`), select the application CI test image with `BAANDER_TEST_IMAGE`, and run the disposable backend coordinator from the repository root:

```bash
# Run all four backend PHPUnit suites in disposable containers
bash scripts/test-backend-container.sh

# Run only unit and custom PHPStan rule suites
bash scripts/test-backend-container.sh --testsuite Unit,StaticAnalysisRules

# Run a single functional or integration test file
bash scripts/test-functional-container.sh tests/Functional/Controller/FavoritesControllerTest.php

# Run Composer validation, a normalization dry run, PHPStan, Deptrac, the import check,
# the tooling architecture, format and PHPStan checks, then all backend suites with coverage
make ci

# Run the standalone tooling test suite and architecture gates
composer run-script --timeout=7200 test:tooling
composer analyse:tooling
composer check:tooling-architecture

# Run web unit tests and Playwright E2E browser suites
yarn --cwd ui/web test
make preview-up
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless
```

These environment and fixture rules apply to every suite:

- **Test domains:** Always use `baander.app` or its subdomains in fixtures and email addresses (`user@baander.app`). Keep HTTP and DNS mocked or routed to disposable local services.
- **Disposable databases:** Functional and integration lanes provision isolated `compose.test.yaml` stacks (`app` → PostgreSQL 18 + PGroonga + Redis). Test worker PHP processes get a 512 MiB memory limit and reporting processes 2 GiB.
- **No production state:** Fixtures under `tests/Fixtures/` prepare and observe only disposable databases. Never point them at an installed or persistent development database.

## Backend & Conventions

| Guide | Coverage |
|-------|----------|
| [Testing Pyramid and E2E Layers](testing-pyramid.md) | Five-layer testing pyramid, boundaries between `Unit`, `Functional`, and `Integration` suites, and the four end-to-end browser verification layers |
| [Backend Runners and Coverage](backend-runners.md) | Host prerequisites, tooling checks, `test-backend-container.sh` lane scheduling (`Unit`, native, functional/integration stacks), coverage validation, `phpstan` / `deptrac`, and CI publication gates |
| [Unit and Functional Conventions](conventions.md) | Manual domain construction, `App\Tests\Functional\TestCase` helpers, `X-Baander-Test-User-Id` authentication, `successResponse()` vs `created()` shapes, `jsonb` float round-trips, DAMA transaction isolation, and controller coverage |
| [Security, Firewall, and Network Suites](security-and-firewall.md) | Refresh-token reuse grace and concurrency (`RefreshTokenGraceTest`, `RefreshTokenConcurrencyTest`), DPoP Redis outage behavior (`DpopStoreOutageTest`), signed video delivery and catalog/playlist mutation firewall matrices, atomic rate-limit storage, and outbound destination / native DNS bounds |

## Workers, Runtime & Media

| Guide | Coverage |
|-------|----------|
| [Messaging and Worker Qualification](workers-and-messaging.md) | Messenger workloads and a chooser for worker setup, lifecycle, capacity, runtime and scheduler checks |
| [Media and Playback Qualification](media-and-playback.md) | Choose native encoding, playback lifecycle, adaptive playback, capacity or deployment checks |
| [Production Runtime Drills](runtime-drills.md) | Container startup probes, worker/outbox/producer runtime drills, Swoole `app:serve` runtime and drain verification (`test-web-shutdown-container.sh`), WebSocket ticket and proxy log security drills, and manual Safari verification on macOS and iOS |

### Worker runners

| Guide | What it checks |
| --- | --- |
| [Worker Runner Setup](worker-runner-setup.md) | Prerequisites and runner selection |
| [Worker Lifecycle](worker-lifecycle-qualification.md) | Containment, retirement, recovery and lifecycle operations |
| [Worker Capacity](worker-capacity-qualification.md) | Ordinary worker scaling and capacity qualification |
| [Worker Runtime](worker-runtime-qualification.md) | Runtime and production worker image checks |
| [Scheduler Qualification](scheduler-qualification.md) | Scheduled work and occurrence persistence |

### Media and playback runners

| Guide | What it checks |
| --- | --- |
| [Native Media](native-media.md) | FFmpeg segment encoder suites and AAC implementation |
| [Playback Lifecycle](playback-lifecycle.md) | Deployment-to-browser playback and client controls |
| [Media Capacity](media-capacity.md) | Shared execution slots and load qualification |
| [Encoder Cost](media-encoder-cost.md) | Per-job and hardware encoder measurement procedures and recorded results |
| [Movie Endpoint Playback](movie-endpoint-playback.md) | Playback through the movie endpoints |
| [Adaptive Playback](adaptive-playback.md) | Quality selection, sparse or delayed output and audio scenarios |
| [Deployment Qualification](deployment-qualification.md) | Complete deployment checks and qualification VMs |
| [Playback Destinations](playback-destinations.md) | Multi-tab close, takeover and recovery behavior |

## Frontend & Client Qualification

| Guide | Coverage |
|-------|----------|
| [Frontend, Browser, and Audio Testing](frontend-and-browser.md) | Choose web, Electron and Bånder Audio Core checks |
| [Browser Harnesses](browser-harnesses.md) | Browser setup and dedicated UI verification |
| [Web Audio Testing](web-audio-testing.md) | Audio graph, spectrum and EQ checks |
| [Web Store Debugging](web-store-debugging.md) | Store isolation and debugging |
| [Live-Update Qualification](live-updates.md) | End-to-end browser verification and production-mode capacity qualification for committed change capture, revision projection, and WebSocket `live.changed` hint delivery |
