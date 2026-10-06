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
| `QualityLadderPortInterface` | Domain contract for the default quality ladder as primitives; consumed by `StreamGovernor`, implemented by Transcode |
| `StreamAdmissionPortInterface` | Synchronous stream admission (budget veto) and completion (learning sample and stream release) |
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

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId` |
| Depended on by | Transcode | Transcode Infrastructure implements the quality-ladder and encoder-profile contracts and calls the stream-admission and allowed-tier contracts. QoL references no Transcode class. |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `QoLAdminService` | Port implementation | Backs `QoLAdminPortInterface`, delegates to the `StreamGovernor` singleton |
| `StreamAdmissionService` | Port implementation | Backs `StreamAdmissionPortInterface`: evaluates the budget and allocates the stream (throws `StreamBudgetExhausted` to veto), records completion samples and releases streams |
| `AllowedQualityTiersService` | Port implementation | Backs `AllowedQualityTiersPortInterface` from the governor's allowed tiers |
| `LearningDataPersister` | Persister | Persists governor learning state to JSON files (dual-throttled writes) |
| `CpuGpuSampler` | Swoole bootable | Samples CPU/GPU utilization every second into a `Swoole\Table` (no pooled services in timer) |
| `MidStreamMonitor` | Swoole timer | Polls utilization every 5s; triggers emergency stream release on sustained over-budget |
| `BudgetGuard` | Port implementation | Mid-stream capacity check against sampled CPU (implements `BudgetGuardInterface`) |

See the [Architecture](../architecture.md#communication-between-contexts) page for details on cross-context event flow, and the [Anti-Corruption Layer](../architecture.md#anti-corruption-layer) page for the streaming-decorator chain.
