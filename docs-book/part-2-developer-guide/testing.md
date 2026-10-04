# Testing

Baander uses PHPUnit 13 with four test suites in the tracked `phpunit.xml.dist`.
Disposable container runners exercise the application's PHP runtime without using
the development database or Redis instance. The Makefile commands remain available
for a configured development test environment.

## Web audio graph

Run `yarn --cwd ui/web test:audio-graph` after installing Chromium with
`yarn --cwd ui/web exec playwright install chromium`. The runner also requires
OpenSSL for its temporary HTTPS certificate. It serves a disposable fixture at
`audio.baander.app`, explicitly mapped to loopback; it does not contact the public
application.

These browser tests render the real `AudioProcessor` nodes with
`OfflineAudioContext` and compare stereo, processing order, and normalization
against reference signals. They replace external WASM analysis modules;
they do not certify production codecs or audible clicks during live graph
changes. A separate loader check serves the production TypeScript loader and
shipped WASM to Chromium, checking instance memory and analysis-state isolation.
Stereo metering cases additionally run the production metering worklet and WASM
against native mono, right-only, asymmetric, and opposite-phase signals, and
compare the independent-channel analyser fallback. The same native cases verify
paired phase samples and correlation; worklet tests also cover quadrature,
silence, ring ordering, and programme reset. Native media tests use locally generated WAV files and the
real playback hook, store, service, and processor to exercise repeated preloaded
handoffs, crossfade overlap, promoted-element controls, and interrupted fades.
One resume regression delays a context-resume rejection across track reselection
and verifies that the newer native playback continues advancing. Hook unit tests
also cover deferred success, native play rejection, and same-track replay.
The active-media error regression reloads an already playing element to a local
404 response, checks the real Chromium `MediaError`, and verifies stop and
reselection recovery. It does not simulate a midstream transport interruption.
These verify element ownership and timing, not sample-accurate gaplessness or
authenticated streaming. Rebuild timing and worklet graph attachment
also have unit regressions in `audio-processor-rebuild.test.ts`. Deferred module
and WASM loading, connection replacement, and React StrictMode cleanup have
processor, service, and playback-hook regressions. Native browser lifecycle tests
use small fixture worklets to verify readiness and teardown; they do not replace
tests of the production WASM algorithms. The browser suite runs as a blocking
step in the frontend workflow.

Run `bash scripts/test-dsp-analysis.sh` with Emscripten 6.0.3 to test the actual
WASM analysis modules and production worklet code against reference signals.
The gate rebuilds twice and checks reproducibility and shipped artifact parity.
See [DSP qualification](../../packages/dsp/README.md) for covered contracts and
the remaining loudness and codec qualification limits.

## Web store isolation and debugging

Run `yarn --cwd ui/web test:store-debug` for the disposable Chromium workflow at
`https://debug.baander.app:5187`, mapped to loopback with a temporary certificate.
It checks production selection/view-mode stores, render isolation, no-op updates,
and enabling, inspecting, exporting, and disabling the store debugger. The frontend
workflow runs this gate. Local prerequisites are Chromium and OpenSSL, as for the
audio graph tests; port 5187 must be free.

In a development build, enable the debug panel from Diagnostics, open Store Timeline
or Store Inspector, and select **Enable store tracing and reload**. Recording is
opt-in through `baander-store-debug` in local storage and is disabled in production
builds. Disabling also reloads, removing tracing wrappers instead of keeping a
conditional branch on every action. Enabling recording incurs snapshot/stack costs.

The timeline links synchronous nested calls, shows changed fields and before/after
state, and exports schema-version-1 JSON. Async completions link to their originating
action; updates after an `await` retain their call stacks without inferred parentage.
History is bounded and reports discarded events. Redacted/truncated/native values
make captures incomplete; exports are diagnostic and do not yet support replay.

Unit tests verify creator/action identity when tracing is disabled, no-op subscriber
and persistence behavior, secret redaction, immutable snapshots, and event ordering.
Player/session tests additionally cover clock isolation and sync under continuous
playback. Performance assertions use counts rather than timing-sensitive thresholds.

## Test Suites

| Suite | Directory | Scope |
|-------|-----------|-------|
| **Unit** | `tests/Unit/` | Domain behavior and application/infrastructure contracts; no Symfony kernel boot |
| **StaticAnalysisRules** | `packages/baander-phpstan-rules/tests/` | Custom PHPStan rules, including parsed-source request-payload fixtures |
| **Functional** | `tests/Functional/` | With Symfony kernel container, database, and services |
| **Integration** | `tests/Integration/` | Database, transport, and other integration contracts against configured test services |

## Running Tests

For strict unit and messaging checks, run from the checkout:

```bash
bash scripts/test-unit-container.sh
bash scripts/test-messaging-container.sh
bash scripts/test-worker-retirement-container.sh
bash scripts/test-worker-recovery-container.sh
bash scripts/test-worker-command-container.sh
bash scripts/test-worker-operator-container.sh
bash scripts/test-docker-context.sh
bash scripts/test-worker-containment-container.sh
bash scripts/test-functional-container.sh tests/Functional/Controller/FavoritesControllerTest.php
bash scripts/test-functional-container.sh tests/Integration/CoverExtractionPersistenceTest.php
bash scripts/test-functional-container.sh tests/Integration/CoverlessAlbumKeysetTest.php
bash scripts/test-functional-container.sh tests/Integration/WorkerDeploymentLeaseSchemaTest.php
```

These require Docker and installed Composer dependencies. The unit runner uses
the application image with networking disabled and explicitly selects
`Unit,StaticAnalysisRules` from `phpunit.xml.dist`. That configuration randomizes
test order within dependency constraints and treats unexpected output as risky.
The unit runner sets a 256 MiB PHP memory limit so the combined unit and PHPStan-rule
suite can compile its analysis container. This budget applies only to that isolated
runner; it does not change the tracked PHPUnit configuration or deployment settings.
The operator runner requires Python 3 and Docker. Its default host mode also
requires PHP with `posix` and `pdo_pgsql` (`BAANDER_TEST_PHP_BINARY` selects its
executable) and a local Unix endpoint (`BAANDER_TEST_DOCKER_ENDPOINT`). The host
controller reaches a loopback-published database port; the worker reaches the same
database through its private network. Its outer 240-second timeout bounds native
database waits; DBAL `connect_timeout` is not a hard connection deadline.

`BAANDER_TEST_OPERATOR_IN_CONTAINER=1` runs the entrypoint in a separate trusted
controller container with PHP and a verified static Docker CLI. The CLI comes from
`docker:29.7.2-cli` by default (`BAANDER_TEST_DOCKER_CLI_IMAGE` overrides it). Only
the controller receives the daemon socket mount. Its SELinux process label is
disabled to access that socket without relabeling it; this exception applies only
to the trusted test controller. Credentials are streamed into private files owned
by its user, and it reaches PostgreSQL on a separate controller network with
no published database port. The controller verifies that its socket reaches the
same daemon before issuing an operator action. The worker keeps its private
network and receives no
mounts or Docker authority. With no explicit endpoint override, setup uses the
runner's ambient Docker connection, supporting CI runners outside the daemon host.

Both modes exercise `bin/worker-deployment.php` create, reconciliation, start,
status and recovery against disposable PostgreSQL/Redis with canonical private
credentials. They verify real outbox delivery, scheduler work, lease/child
identities and retirement of the immutable boot. Both modes pass locally, including
container mode from a workspace with only the runner script and no host PHP or
Composer dependencies. The fixture image does not certify the production build.
Forgejo now includes a
blocking operator lifecycle step using container mode and
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1` to copy source and dependencies from the built
image. Adding that gate does not establish a passing Forgejo run. Status does not
certify readiness.

To check the actual production worker target, build it separately and pass its
local image reference to the production runner:

```bash
docker buildx build --target worker --load -t baander-worker:test .
BAANDER_TEST_IMAGE=baander-worker:test bash scripts/test-worker-production-container.sh
```

The runner has a 300-second outer deadline; image build time is outside that bound.
It verifies the worker user, entrypoint, disabled web health check and production
environment and disabled PHP error display; checks that development dependencies,
baked OAuth keys and agent artifacts are absent; and runs Composer's production platform checks. It then uses
`BAANDER_TEST_USE_IMAGE=1` with container controller mode to run the lifecycle
against that exact immutable image. This skips the fixture rebuild and injects no
source, dependencies, keys or cache into the worker. Test preparation and Docker
authority remain in separate containers. The scope is the worker lifecycle,
outbox and scheduler, without OAuth certification. Forgejo now includes blocking
context-exclusion, production-worker build and production-lifecycle steps. Their
configuration does not establish a passing Forgejo run. The clean worker build,
platform checks and direct-image lifecycle pass locally with native Swoole pinned
to Composer's required 6.2.0. PIE installation failures fail the image build.

The messaging runner waits for PostgreSQL TCP readiness (not its temporary
initialization socket) and prints service logs on readiness timeout. It also
explicitly selects `phpunit.xml.dist` and provisions
disposable PostgreSQL and Redis for its selected transport, outbox, access-token
cache transaction, deployment lease, and PGroonga compatibility tests. The lease
tests use the actual migration and independent PostgreSQL connections to exercise
ownership, expiry, lock contention, stale epochs and uncertain commit recovery.
The runner includes `bin/worker-lease-agent.php` and exercises fresh helper processes
against PostgreSQL, including blocked calls, parent deadlines, cancellation,
malformed responses and output limits. Unit tests separately exercise monotonic
lease authority and the runtime coordinator with controlled helper responses;
they verify that pending renewal cannot block worker shutdown at lease expiry.
The separate schema test above uses the fully migrated application kernel to check
that ORM introspection preserves the DBAL-owned lease table. The search tests exercise
production Doctrine filtering and scored SQL against mapped PGroonga indexes;
they require the project's extension-capable database image. Both runners fail on
PHPUnit notices and skipped tests. Neither reads an
ignored local `phpunit.xml` or runs the whole functional suite. The Makefile's
`phpunit`, `paratest`, and `ci` targets also select
`phpunit.xml.dist` explicitly. A configured development test environment can use
`make exec`:

```bash
# Run all tests
make phpunit

# Run a single test file
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Catalog/Domain/Model/AlbumTest.php"

# Run a specific suite
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --testsuite Unit"

# Run the custom PHPStan rule tests
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --testsuite StaticAnalysisRules"

# Run a specific controller's functional tests
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --filter NotificationControllerTest"

# Run a single test method
make exec cmd="./vendor/bin/phpunit -c phpunit.xml.dist --filter testCreateAlbum"

# Run with Xdebug off (faster)
make exec cmd="XDEBUG_MODE=off ./vendor/bin/phpunit -c phpunit.xml.dist"
```

The worker containment runner creates dedicated, disposable containers with one
CPU, 256 MiB of memory, no networking, no added capabilities, and no host mounts.
It runs the supervisor core as PID 1 with a child and a TERM-ignoring descendant.
Both graceful TERM and supervisor SIGKILL must stop the container and descendant
activity within the test deadline. The fixture distinguishes direct-child drain
from verified containment: it exits as PID 1 without acknowledging containment or
releasing a deployment lease, then the external runner checks namespace shutdown.
The runner fails on unexpected exit status or
OOM and removes its containers on exit. It tests the proposed container boundary;
it does not certify per-child descendant cleanup on restart, host systemd behavior,
deployment lease takeover, or measured production resource defaults.

The retirement runner additionally needs host PHP and Docker CLI access to a local
Unix daemon endpoint. It starts a restricted restart-always deployment fixture,
rejects an incorrect boot identity, then exercises the real external retirement
adapter. Confirmed force-removal must prevent restart of the old full container ID;
a subsequent absent-container request must fail closed. PostgreSQL controller tests
in the messaging runner separately verify that retirement failures preserve the
lease and concurrent recovery cannot release a newer epoch. The recovery runner combines the real Docker adapter with PostgreSQL lease
coordination. It needs host PHP with `pdo_pgsql`, publishes only an ephemeral
loopback database port, and destroys its disposable resources. It verifies
wrong-owner and wrong-label rejection, expiry without takeover, confirmed removal,
new epoch acquisition and stale-token rejection. The fixture exercises external
controller startup and recovery, not a complete `LeasedWorkerRuntime` deployment.
The predecessor is created with restart disabled and remains unstarted until its
inventory binding and one-shot start claim commit. The fixture checks visibility
through an independent PostgreSQL connection at the real Docker start call, then
verifies that a repeated controller start cannot restart the predecessor. A second
fixture makes Docker create a real container and then discards its acknowledgment.
It verifies durable creation admission, refusal to recreate, exact-recipe
reconciliation without process activity, and registered startup/recovery.

The worker-command runner boots the actual `app:worker` as PID 1 against disposable
production-mode PostgreSQL and Redis after fresh and repeat migrations. It verifies
three direct child roles, their exact committed deployment tuple (overriding a bogus
inherited epoch), and the namespace/boot-qualified Redis consumer. It kills the
consumer and scheduler separately to require whole-container draining, tests TERM and forced database lease
expiry, and confirms a duplicate
lease claim exits with zero launch attempts. It checks that ownership remains
reserved after shutdown. It also seeds a real registration event before startup,
requires the supervised relay to commit the notification, receipt and channel
handoffs, requires Redis acknowledgment with no pending, delayed or failed work,
and repeats the checks after clearing the event acknowledgment. Outbound
preferences are disabled, the disposable user is unverified and no webhook
endpoints exist; no external delivery is requested. A paused manual console request
runs the registered cache sweep in dry-run mode through the dedicated scheduler
stream; duplicate delivery must not invoke it twice. These checks do not establish
application readiness, autoscaling, production capacity or media ownership.
CI runs this as a blocking step using `BAANDER_TEST_CHECKOUT_IN_IMAGE=1`, which
copies source and Composer dependencies from the built application image.
The functional `Console/ServeCommandTest` verifies that `app:serve` and the legacy
server name resolve to the same real command and preserve its options.

The functional runner creates an isolated PostgreSQL/Redis network, extracts the
checkout into a fresh directory, and runs all migrations twice before executing the
requested PHPUnit paths or options. With no arguments it selects `tests/Functional`.
The second migration run checks that the recorded history produces no pending work.

Scheduler occurrence persistence can be checked with:

```bash
bash scripts/test-functional-container.sh tests/Integration/SchedulerOccurrenceStoreTest.php tests/Integration/SchedulerOccurrenceExecutionStoreTest.php tests/Integration/SchedulerOccurrenceGuardTest.php tests/Integration/SchedulerOccurrenceDispatchStoreTest.php tests/Integration/SchedulerOccurrenceRelayTest.php tests/Integration/WorkerDeploymentLeaseSchemaTest.php
```

These checks cover immutable snapshots, independent-connection visibility,
competing inserts, rollback and a lost commit acknowledgment. The actual migration
also enforces command/JSON bounds and minute-aligned instants; Doctrine's schema
filter preserves the tables. Execution checks require a committed attempt before
the nested effect, verify the configured Kernel services, and force a failure
after an effect through actual Redis/Messenger retries. Redelivery must leave the
consumed attempt intact without repeating the effect. Missing/future occurrences,
lost commit acknowledgments and wrong-owner return markers cannot grant execution.
Admission also rejects absent, malformed, expired or replaced deployment authority
without consuming an attempt. Tests cover lease-row contention, authority lost
during insertion with transaction rollback, and exact historical-owner receipts
after expiry or replacement. The existing fresh-install execution migration
requires the deployment tuple; this rewrites undeployed history and does not
upgrade or reset an existing local database.
Dispatch tests exercise committed reservations, skipped locked rows, token replacement
and idempotent acceptance receipts on PostgreSQL. Real Redis tests send through the
explicit JSON serializer and reproduce acceptance followed by a lost acknowledgment;
recovery republishes the same occurrence identity without granting a second attempt.
A recorded acceptance stops repeated publication while consumers are stopped. Unit
tests require the rest of a reserved batch to be attempted after a failed send or
receipt. The configured Kernel resolves the relay and its explicit async sender.
This does not test automatic polling, fencing effects after lease expiry, broker
crash durability or native console/media execution. No occurrence relay loop is
enabled yet.

Scheduled-job source parameters can be checked against the real ORM and PostgreSQL:

```bash
bash scripts/test-functional-container.sh tests/Integration/ScheduledJobParameterPersistenceTest.php
```

These checks verify the physical JSON column, fresh ORM reads, parameter order and
numeric representation before materializing an occurrence, plus the relevant schema
comparison. Native JSON preserves the encoded values; it does not solve stale
schedule writes or Doctrine's signed-zero-only dirty-checking limitation.

The occurrence console adapter and worker-stop policy can be checked with:

```bash
bash scripts/test-functional-container.sh tests/Integration/ScheduledConsoleExecutorTest.php tests/Integration/ScheduledConsoleWorkerStopTest.php tests/Integration/SchedulerOccurrenceGuardTest.php
```

Real CLI children verify literal argument handling, PHP heap settings, concurrent
stdout/stderr draining, output limits, deadlines, ignored TERM followed by KILL,
unexpected signals and exact direct-child reaping. The actual Messenger worker
must stop before its next queued job after an uncertain result, including nested
handler exceptions; ordinary known failures may continue. Kernel checks verify the
executor port and stop subscriber registration. These fixtures do not certify
business-command side effects, descendant containment, kernel-stalled processes or
total deployment memory. The occurrence handler's unit tests also require the
paused state and uncertainty to survive persistence failures without issuing a
return receipt or granting a second execution.

The functional runner also supplies the isolated PostgreSQL/Redis environment
aliases used by integration tests that need the full migrated schema and kernel.
`CoverExtractionPersistenceTest` disables DAMA rollback with its supported attribute
and observes commits through a second connection. It injects a real flush failure,
then verifies that the worker's Swoole pool release and Symfony reset allow the
same handler to retry successfully. Its exact expected pool-reset diagnostic is
asserted; unexpected-output checks remain enabled.
It uses a 512 MiB PHP limit and a 300-second overall timeout. Database and Redis
values supplied through the environment override the tracked PHPUnit defaults.
The test firewall uses the test authenticator; this does not verify production
bearer-token or DPoP authentication. The Favorites controller selection is verified;
the runner's availability does not establish that the entire functional suite passes.

Migration ordering is configured through `MigrationVersionComparator`. It preserves
legacy class identities while placing their table creation before dependent changes.
New migrations use `VersionYYYYMMDDHHMMSS`; unfamiliar naming formats need an
explicit ordering review. Do not rename applied migrations to change their order.
Existing irreversible migrations still prevent a general full downgrade.

## Coverage

`make phpunit` runs with HTML, Clover, and JUnit report generation:

- **HTML**: `reports/coverage/`
- **Clover**: `reports/clover.xml`
- **JUnit**: `reports/junit.xml`

## Unit Test Conventions

- **Manual object construction** — tests build domain objects directly (e.g., `Album::create(...)`) rather than using factories. Zenstruck Foundry is available but not the default convention.
- **Test file structure** mirrors `src/` — a test for `src/Catalog/Domain/Model/Album.php` lives in `tests/Unit/Catalog/Domain/Model/AlbumTest.php`.
- **No mocks in domain tests** — unit tests exercise real domain logic. Mocks are reserved for infrastructure and external dependencies.
- Use `createStub()` when configuring return values without interaction expectations; use `createMock()` when the test asserts calls. Strict runs treat PHPUnit notices as failures.
- Use `baander.app` or its subdomains for test domains and email addresses. Keep HTTP/DNS mocked or explicitly routed to disposable local services; the project domain does not authorize production traffic. Preserve literal IP cases that test network boundaries. Some inherited fixtures still use other domains; do not copy those examples into new tests.

### Example: Unit Test (Domain Logic)

Unit tests live in `tests/Unit/` and test pure domain logic with no framework or container:

```php
final class AlbumTest extends TestCase
{
    public function testCreateAlbum(): void
    {
        $album = Album::create(
            libraryId: Uuid::generate(),
            title: 'Abbey Road',
            type: 'album',
        );

        $this->assertNotNull($album->getId());
        $this->assertNotNull($album->getPublicId());
        $this->assertSame('Abbey Road', $album->getTitle());
    }

    public function testCreateAlbumWithEmptyTitleThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Album::create(
            libraryId: Uuid::generate(),
            title: '',
            type: 'album',
        );
    }
}
```

### Example: Unit Test (Application Handler)

Application handler tests mock domain interfaces (repositories, ports) but test real orchestration logic:

```php
final class StartPlaybackHandlerTest extends TestCase
{
    public function testStartsPlaybackAndDispatchesEvent(): void
    {
        $sessionPort = $this->createMock(PartySessionPortInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $handler = new StartPlaybackHandler($sessionPort, $eventDispatcher);

        $session = /* ... create or reconstitute a PartySession ... */;

        $sessionPort->method('findByUuid')->willReturn($session);
        $sessionPort->expects($this->once())->method('startPlayback');
        $eventDispatcher->expects($this->once())->method('dispatch');

        $command = new StartPlaybackCommand(sessionId: $session->getId(), position: 0);
        ($handler)($command);
    }
}
```

## Functional Test Conventions

Functional tests live in `tests/Functional/` and extend `App\Tests\Functional\TestCase`, which provides a `KernelBrowser`, test user factories, and request helpers. Each test is wrapped in a database transaction by DAMA DoctrineTestBundle, so tests share a single connection and never pollute each other.

### Base Class: `App\Tests\Functional\TestCase`

| Method | Purpose |
|--------|---------|
| `createTestUser(?string $email, string $name, string $password)` | Creates a `ROLE_USER` user with a random email |
| `createAdminUser()` | Creates a `ROLE_ADMIN` user |
| `createSuperAdminUser()` | Creates a `ROLE_SUPER_ADMIN` user |
| `authenticatedRequest(string $method, string $uri, User $user, array $content)` | Sends an HTTP request as the given user. Sets `X-Test-User-Id` header, which the `TestAuthenticator` picks up to authenticate without a real JWT. |
| `anonymousRequest(string $method, string $uri, array $content)` | Sends an unauthenticated HTTP request |
| `assertJsonResponse(Response $response, int $expectedStatus, ?string $expectedKey)` | Asserts status code + valid JSON. If `$expectedKey` is set, asserts the key exists in the decoded body. Returns the decoded array. |

### Authentication in Tests

Tests do not use real JWT tokens or `loginUser()`. Instead, the `TestAuthenticator` (registered in `config/packages/test/security.yaml`) reads the `X-Test-User-Id` header and returns a `SecurityUser` for the corresponding user ID. This is set automatically by `authenticatedRequest()`.

```php
// Anonymous request — no auth headers
$response = $this->anonymousRequest('GET', '/api/genres/');
$this->assertJsonResponse($response, 401);

// Authenticated as a regular user
$user = $this->createTestUser();
$response = $this->authenticatedRequest('GET', '/api/genres/', $user);
$this->assertJsonResponse($response, 200);

// Authenticated as admin
$admin = $this->createAdminUser();
$response = $this->authenticatedRequest('POST', '/api/genres/', $admin, [
    'name' => 'Rock',
    'slug' => 'rock',
]);
$this->assertSame(201, $response->getStatusCode());
```

### Response Shape Gotchas

Controllers use two different response shapes — know which one your test expects:

| Method | Shape | When to use |
|--------|-------|-------------|
| `successResponse($data)` | `{"data": {...}}` | Most GET, PATCH, PUT responses |
| `created($data)` | `{...}` (flat, no wrapper) | POST 201 responses — the data is the top-level object |
| `paginatedResponse($p)` | `{"data": [...], "meta": {...}}` | List endpoints with pagination |
| `errorResponse($msg, $status)` | `{"error": $msg, "status": $status}` | Error responses |

When asserting on a `created()` response, decode the body directly instead of passing `'data'` as the expected key:

```php
// created() — flat response
$response = $this->authenticatedRequest('POST', '/api/favorites/', $user, [...]);
$this->assertSame(201, $response->getStatusCode());
$data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
$this->assertSame('song', $data['entityType']);

// successResponse() — wrapped in 'data'
$data = $this->assertJsonResponse(
    $this->authenticatedRequest('GET', '/api/favorites/', $user),
    200,
    'data',
);
$this->assertSame('song', $data['data'][0]['entityType']);
```

### PostgreSQL jsonb Float Round-Trip

PostgreSQL `jsonb` stores numbers without type annotations. A whole-number float like `5.0` survives the PHP → PostgreSQL → PHP round-trip as integer `5`, which fails a `assertSame(5.0, ...)` assertion. Always use non-whole-number floats in test payloads:

```php
// Bad — survives round-trip as int 5
['volume' => 5.0]

// Good — survives round-trip as float 5.5
['volume' => 5.5]
```

### DAMA Transaction Isolation

Functional tests using the configured DAMA extension are wrapped in a transaction
that is rolled back on teardown. This means:

- **No cleanup needed** — inserted rows vanish automatically.
- **Single connection** — all tests share one DB connection within a test run.
- **`$client->disableReboot()`** — the base class calls this to prevent the kernel from rebooting between requests, which would break the transaction.

This shared test connection cannot prove what another connection sees after a
producer commits or rolls back. Production runtime drills use independent
connections and disposable services for that purpose; their fixtures are not
DAMA-wrapped functional tests.

## Production Runtime Drills

```bash
bash scripts/test-container-startup.sh
timeout 180 bash scripts/test-worker-runtime-container.sh
bash scripts/test-worker-command-container.sh
bash scripts/test-outbox-runtime-container.sh
bash scripts/test-producer-runtime-container.sh
```

These run the production kernel and actual repositories against disposable
PostgreSQL and Redis on isolated networks, with cleanup on exit. The startup
contract uses isolated executable probes to check argument, PID and signal handling.
The legacy worker-runtime drill checks transport redelivery using a historical
Supervisor fixture; it does not represent deployment packaging. The worker-command
drill exercises the current PID-1 supervisor and committed lease lifecycle.
The outbox drill checks
notification projections, receipts, channel handoffs, and replay after a lost
acknowledgement. The producer drill rejects outbox writes, verifies rollback
through a second connection, and retries through the same bus. It covers operator
user creation, registration tokens/preferences, email verification token retention,
and recovery after an ORM flush closes the entity manager. This is coverage of
three auth producers, not every event producer or a whole-schema audit.

Fixtures under `tests/Fixtures/` prepare and observe only those disposable
databases. Do not invoke them against an application database. The runtime runners
accept `BAANDER_TEST_IMAGE` and `BAANDER_TEST_POSTGRES_IMAGE`; CI sets
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1` to test the checkout already built into its image.
Outbox and producer runners also enforce their own 180-second timeout.

### Example: Functional Test (Full Pattern)

```php
final class FavoritesControllerTest extends TestCase
{
    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/favorites/');
        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsAddedFavorites(): void
    {
        $user = $this->createTestUser();
        $this->addFavorite($user, 'song', 'song-abc123');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/favorites/', $user),
            200,
        );

        $this->assertCount(1, $data['data']);
        $this->assertSame(1, $data['meta']['total']);
    }

    public function testAddReturns201(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/favorites/', $user, [
            'entityType' => 'song',
            'entityPublicId' => 'song-create',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('song', $data['entityType']);
    }

    private function addFavorite($user, string $entityType, string $entityPublicId)
    {
        return $this->authenticatedRequest('POST', '/api/favorites/', $user, [
            'entityType' => $entityType,
            'entityPublicId' => $entityPublicId,
        ]);
    }
}
```

## Functional Test Coverage

The functional suite covers these controllers (tests live in `tests/Functional/Controller/`):

| Context | Controller | Tests | Notes |
|---------|-----------|-------|-------|
| Session | DeviceController | 19 | Device registration + rename |
| Notification | PushSubscriptionController | 15 | Subscribe/unsubscribe/remove-all |
| Notification | NotificationController | 19 | List, markRead, markAllAsRead, delete |
| Notification | WebhookController | 13 | Admin-gated CRUD |
| UserPreference | AccentColorController | 7 | GET + PUT |
| UserPreference | AudioPreferencesController | 14 | Versioned save, history, rollback |
| UserPreference | LayoutPreferencesController | 13 | Strict 2-field payload |
| UserPreference | PlayerPreferencesController | 13 | Strict 9-field payload |
| UserPreference | EqDeviceProfileController | 20 | CRUD + activate, pins ownership gaps |
| UserPreference | SidebarConfigController | 10 | Per-media-type config |
| UserPreference | ThemeMoodController | 7 | GET + PUT |
| Catalog | GenreController | 16 | CRUD, ROLE_ADMIN on write |
| Favorites | FavoritesController | 13 | Add/list/remove, type filter |
| Shared | HealthCheckController | 5 | /health, /ready, /live probes |

## Bugs Found by Functional Tests

The functional suite has surfaced production bugs that were fixed:

- **Push subscribe `Assert\Choice`** — `Assert\Choice([...])` used positional args; Symfony 7 requires `Assert\Choice(choices: [...])`. Subscribe was always 500.
- **Notification `findByPublicId`** — passed a raw string to a `PublicIdType` Doctrine column, which rejects non-PublicId values. Mark-read and delete were always 500.
- **Notification `save()`** — never persisted the domain model's `publicId`, so the entity got a divergent listener-generated ID.
- **Notification `markAllAsRead`** — used `->set('e.isRead', true)`, which PHP coerced to string `"1"`, causing a PostgreSQL boolean type mismatch. Always 500.
- **Notification `markRead` controller** — returned the stale domain model (isRead:false) after marking read.
- **GenreController missing authorization** — `update()` and `destroy()` lacked `#[IsGranted('ROLE_ADMIN')]`. Any authenticated user could modify or delete genres.
- **Missing `user_theme_moods` migration** — the ThemeMood entity had no database table.

### Design Gaps Pinned by Tests (Not Yet Fixed)

Some tests document known design-level gaps by asserting the *current* behavior rather than the ideal:

- **Ownership checks missing** — Notification markRead/delete and EqDeviceProfile show/update/delete have no `userId` filter; any authenticated user can access other users' resources by ID.
- **Not-found returns 500, not 404** — EqDeviceProfile's port throws `InvalidArgumentException` for not-found, which the ExceptionSubscriber maps to HTTP 500 instead of 404.
- **Dead-code optimistic locking** — AudioPreferences, LayoutPreferences, and PlayerPreferences controllers catch `RuntimeException` for version conflicts, but `saveForUser()` never throws it. The 409 response path is unreachable.
- **Favorites missing Choice constraint** — `AddFavoriteRequest.entityType` has `NotBlank` but no `Choice`, so invalid types pass DTO validation and crash in `FavoriteType::from()` with a 500.

## Static Analysis

PHPStan runs alongside tests:

```bash
# Run PHPStan
make phpstan

# Generate a baseline for existing errors
make phpstan-baseline
```

## Frontend Testing

The web frontend uses Vitest:

```bash
cd ui/web
yarn test              # Run tests
yarn test:watch        # Watch mode
yarn test:coverage     # With coverage
```

See the [Frontend Development](frontend-development.md) page for more details.

The native audio graph suite also runs normalization against the shipped loudness
WASM without mounting Equalizer. It checks output attenuation, independent volume
and mute, chain rebuilds, disable, and programme reset. Unit coverage checks
settings restoration, native-readiness rejection, expiry, and passive playback
volume ownership.
