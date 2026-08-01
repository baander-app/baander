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
| `UtilizationSample` | Value object | A completed stream's measured utilization (CPU/GPU/encode FPS + stream params) used for training |
| `StreamBudgetExhausted` | Exception | Thrown when no quality tier fits the remaining budget; carries structured 503 response data |

## Ports

| Port | Purpose |
|------|---------|
| `QoLAdminPortInterface` | Admin read/write surface over `StreamGovernor`: status, active streams, profile switching, learning reset |

## API Endpoints

All endpoints are prefixed with `/api/admin/qol` and require admin roles.

| Method | Path | Authorization | Purpose |
|--------|------|---------------|---------|
| GET | `/api/admin/qol/status` | `ROLE_ADMIN` | Governor status (state, profile, active streams, sample count, model readiness, budget cap) |
| GET | `/api/admin/qol/streams` | `ROLE_ADMIN` | List active streams with quality tier and predicted cost |
| PATCH | `/api/admin/qol/profile` | `ROLE_SUPER_ADMIN` | Switch algorithm profile (`conservative` / `balanced` / `aggressive`) |
| POST | `/api/admin/qol/reset` | `ROLE_SUPER_ADMIN` | Clear learning data and return to `Learning` state |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId` |
| Depends on | Transcode | `QualityLadderPortInterface`, `TranscodeJobPortInterface`, `TranscodeStreamingPortInterface`, `BudgetGuardInterface`, `QualityLadder`, `HardwareCapabilitiesProber`, and the `TranscodeJobCompleted` / `TranscodeSessionAttached` events |
| Depended on by | Transcode | `BudgetGuard` and `QualityFilteringStreamingDecorator` are wired into the transcode streaming chain |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `QoLAdminService` | Port implementation | Backs `QoLAdminPortInterface`, delegates to the `StreamGovernor` singleton |
| `SessionBudgetSubscriber` | Event subscriber | Intercepts `TranscodeSessionAttached` (priority 1), evaluates budget, vetoes via `StreamBudgetExhausted` |
| `LearningEngineSubscriber` | Event subscriber | Records `UtilizationSample`s from `TranscodeJobCompleted` events |
| `LearningDataPersister` | Persister | Persists governor learning state to JSON files (dual-throttled writes) |
| `CpuGpuSampler` | Swoole bootable | Samples CPU/GPU utilization every second into a `Swoole\Table` (no pooled services in timer) |
| `MidStreamMonitor` | Swoole timer | Polls utilization every 5s; triggers emergency stream release on sustained over-budget |
| `QualityFilteringStreamingDecorator` | Streaming decorator | Filters DASH/master manifests to the governor's allowed tiers (decoration priority -1) |
| `BudgetGuard` | Transcode guard | Mid-segment capacity check before each segment dispatch (implements `BudgetGuardInterface`) |

See the [Architecture](../architecture.md#communication-between-contexts) page for details on cross-context event flow, and the [Anti-Corruption Layer](../architecture.md#anti-corruption-layer) page for the streaming-decorator chain.
