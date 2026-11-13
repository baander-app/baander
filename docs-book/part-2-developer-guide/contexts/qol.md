# QoL

The QoL ("quality of life") context is a stream-governance layer over the Transcode context. It predicts per-stream CPU cost from observed utilization, enforces a global CPU budget across concurrent transcodes, downgrades quality tiers (or rejects streams) when the budget is exhausted, filters manifests to the allowed tiers, and monitors streams mid-flight for emergency release. QoL is service-based: it has **no aggregate roots** — its state is an in-memory `StreamGovernor` whose learning data is persisted to JSON files by infrastructure subscribers.

## Components

### Domain Services

| Service | Purpose |
|---------|---------|
| `StreamGovernor` | Core domain service: budget evaluation, tier downgrade/rejection, active-stream tracking, profile management, learning-model coordination, and state export/import |
| `LearningModel` | Linear-regression model (4-feature OLS via Gaussian elimination) predicting per-stream CPU% from source height, target bitrate, and hardware-acceleration flag; trains at 50 samples, falls back to per-tier averaging |

### Domain Models & Value Objects

| Model | Type | Purpose |
|-------|------|---------|
| `GovernorState` | Enum | `learning` / `active` lifecycle of the governor |
| `AlgorithmProfile` | Enum (VO) | Budget profile: `conservative` (70%), `balanced` (80%), `aggressive` (90%) |
| `StreamAllocation` | Value object | An allocated active stream (job ID, quality tier, predicted cost) |
| `EncoderWork` | Value object | A stream's encoder CPU seconds and output seconds, and the cost they give as a share of the host's CPUs; published with `StreamAdmissionPortInterface` |
| `UtilizationSample` | Value object | A completed stream's measured utilization (CPU/GPU/encode FPS + stream params) used for training |
| `StreamBudgetExhausted` | Exception | Thrown when no quality tier fits the remaining budget; carries structured 503 response data |

## Ports

| Port | Purpose |
|------|---------|
| `AlgorithmProfileStoreInterface` | Domain contract storing the active `AlgorithmProfile` across workers (`AlgorithmProfileTable`) |
| `QoLAdminPortInterface` | Admin read/write surface over `StreamGovernor`: status, active streams, profile switching, learning reset |
| `QoLJobIdentityInterface` | Maps internal transcode job UUIDs to 21-character public IDs (`QoLJobIdentity`) |
| `QualityLadderPortInterface` | Domain contract for the default quality ladder as primitives; consumed by `StreamGovernor`, implemented by Transcode |
| `StreamAdmissionPortInterface` | Synchronous stream admission (budget veto), observation of the running stream's `EncoderWork`, and completion (learning sample and stream release) |
| `AllowedQualityTiersPortInterface` | Tier names the budget currently allows, used for manifest filtering |
| `BudgetGuardInterface` | Mid-stream capacity check against sampled CPU; registered, with no caller since long FFmpeg streams replaced per-segment dispatch |
| `EncoderProfileFingerprintPortInterface` | Encoder configuration name stored with persisted learning state; implemented by Transcode |

## API Endpoints

All endpoints are prefixed with `/api/admin/qol` and require admin roles.

| Method | Path | Authorization | Purpose |
|--------|------|---------------|---------|
| GET | `/api/admin/qol/status` | `ROLE_ADMIN` | Governor status (state, profile, active streams, sample count, model readiness, budget cap) |
| GET | `/api/admin/qol/streams` | `ROLE_ADMIN` | List active streams with quality tier and predicted cost |
| PATCH | `/api/admin/qol/profile` | `ROLE_SUPER_ADMIN` | Switch algorithm profile (`conservative` / `balanced` / `aggressive`) |
| POST | `/api/admin/qol/reset` | `ROLE_SUPER_ADMIN` | Clear learning data and return to `Learning` state |

### Console Commands

| Command | Purpose |
|---------|---------|
| `app:qol:status` (`QoLStatusCommand`) | Show the stream governor status of every web server worker |
| `app:qol:streams` (`QoLStreamsCommand`) | List the streams every web server worker has admitted, with their predicted cost |
| `app:qol:profile` (`QoLProfileCommand`) | Set the stream governor's algorithm profile in every web server worker |
| `app:qol:reset` (`QoLResetCommand`) | Discard the stream governor's learning data in every web server worker and return each to the `Learning` state |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `ServerControlPortInterface` |
| Depended on by | Transcode | Transcode Infrastructure implements the quality-ladder and encoder-profile contracts and calls the stream-admission and allowed-tier contracts. QoL references no Transcode class. |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `QoLAdminService` | Port implementation | Backs `QoLAdminPortInterface` by fanning out control operations across HTTP workers' `StreamGovernor` instances via `ServerControlPortInterface` (`QoLStatusOperation`, `QoLStreamsOperation`, `QoLProfileSetOperation`, `QoLLearningResetOperation`) |
| `QoLJobIdentity` | Port implementation | Backs `QoLJobIdentityInterface`, resolving internal `transcode_jobs` UUIDs to `PublicId` values |
| `StreamAdmissionService` | Port implementation | Backs `StreamAdmissionPortInterface`: evaluates the budget and allocates the stream (throws `StreamBudgetExhausted` to veto), records completion samples and releases streams |
| `AllowedQualityTiersService` | Port implementation | Backs `AllowedQualityTiersPortInterface` from the governor's allowed tiers |
| `AlgorithmProfileTable` | Swoole bootable | Backs `AlgorithmProfileStoreInterface` with a pre-fork `Swoole\Table` and atomic file persistence (`algorithm_profile.json`) |
| `LearningDataPersister` | Persister | Persists governor learning state to JSON files (dual-throttled writes) |
| `QoLWorkerStartupSubscriber` | Event subscriber | Restores saved learning state on HTTP worker startup, starts `MidStreamMonitor` in worker 0, and saves state on worker drain |
| `WorkerQoLLifecycle` | Worker lifecycle | Starts and stops `AlgorithmProfileTable`, `LearningDataPersister`, and `MidStreamMonitor` for an admitted video encode child |
| `MidStreamMonitor` | Swoole timer | Polls `$this->governor->observedLoad()` (measured encoder CPU seconds per output second against `HostCpus`) every 5s; triggers emergency stream release on sustained over-budget |
| `HostCpus` | Infrastructure helper | Resolves the count of online host CPUs via `posix_sysconf(POSIX_SC_NPROCESSORS_ONLN)` |
| `BudgetGuard` | Port implementation | Mid-stream capacity check against sampled CPU (implements `BudgetGuardInterface`) |
| `StreamBudgetExceptionListener` | Exception listener | Converts `StreamBudgetExhausted` into a structured HTTP 503 JSON response |

See the [Architecture](../architecture/overview.md#communication-between-contexts) page for details on cross-context event flow, and the [Anti-Corruption Layer](../architecture/overview.md#anti-corruption-layer) page for the streaming-decorator chain.
