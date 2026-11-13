# Testing

Bånder verifies backend domain logic, persistence, asynchronous workers, native media encoding, and browser/client behavior through isolated test suites and disposable container runners. Use this index to choose the right runner for your change or jump directly to the guide for a specific subsystem.

## Test Suites

| Suite | Directory | Scope |
|-------|-----------|-------|
| **Unit** | `tests/Unit/` | Domain behavior and application/infrastructure contracts; no Symfony kernel boot |
| **StaticAnalysisRules** | `packages/baander-phpstan-rules/tests/` | Custom PHPStan rules, including parsed-source request-payload fixtures |
| **Functional** | `tests/Functional/` | HTTP controllers, console commands, and application flows with Symfony kernel, PostgreSQL, and Redis |
| **Integration** | `tests/Integration/` | Database schema, firewall matrices, transport delivery, and concurrency contracts against disposable services |
| **Tooling** | `scripts/tests/php/` | Standalone build, deployment, website, and test-runner orchestration contracts (`phpunit-tooling.xml.dist`) |

## Quick Start

Generate the standalone tooling autoloaders (`composer tooling:autoload`), select the application CI test image (`BAANDER_TEST_IMAGE`), and run the disposable backend coordinator from the repository root:

```bash
# Run all four backend PHPUnit suites in disposable containers
bash scripts/test-backend-container.sh

# Run only unit and custom PHPStan rule suites
bash scripts/test-backend-container.sh --testsuite Unit,StaticAnalysisRules

# Run a single functional or integration test file
bash scripts/test-functional-container.sh tests/Functional/Controller/FavoritesControllerTest.php

# Run static analysis and architecture gates (PHPStan, Deptrac, Composer validation)
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

Key environment and fixture rules across all suites:

- **Test domains:** Always use `baander.app` or its subdomains in fixtures and email addresses (`user@baander.app`); keep HTTP and DNS mocked or routed to disposable local services.
- **Disposable databases:** Functional and integration lanes provision isolated `compose.test.yaml` stacks (`app` → PostgreSQL 18 + PGroonga + Redis) with 512 MiB worker limits and 2 GiB reporting limits.
- **No production state:** Fixtures under `tests/Fixtures/` prepare and observe only disposable databases; never point them at an installed or persistent development database.

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
| [Messaging and Worker Qualification](workers-and-messaging.md) | Validated Messenger workloads, Redis fallback without Swoole (`BAANDER_TEST_DISABLE_SWOOLE=1`), worker containment/retirement/recovery/command/operator/lifecycle runners, production worker image checks, ordinary worker autoscaling qualification, and scheduler occurrence persistence |
| [Media and Playback Qualification](media-and-playback.md) | Native FFmpeg segment encoder suites (`SegmentEncoder*MediaTest`), two-command deployment and Chromium/Firefox playback checkpoints, shared media control qualification (per-job CPU/memory cost, VAAPI hardware cost, 8 + 12 slot load proof), stream selection, sparse/delayed playback, deployment qualification, and the playback destination specs for close reports and "Play here anyway" |
| [Production Runtime Drills](runtime-drills.md) | Container startup probes, worker/outbox/producer runtime drills, Swoole `app:serve` runtime and drain verification (`test-web-shutdown-container.sh`), WebSocket ticket and proxy log security drills, and manual Safari verification on macOS and iOS |

## Frontend & Client Qualification

| Guide | Coverage |
|-------|----------|
| [Frontend, Browser, and Audio Testing](frontend-and-browser.md) | Web Vitest and Playwright E2E suites, 32-combination mood × accent styling and recessed-footer checks, Web audio graph and spectrum/EQ calibration, browser authentication transport, web typography, store isolation and debugging, Electron Node tests, and Bånder Audio Core CTest and binding suites |
| [Live-Update Qualification](live-updates.md) | End-to-end browser verification and production-mode capacity qualification for committed change capture, revision projection, and WebSocket `live.changed` hint delivery |
