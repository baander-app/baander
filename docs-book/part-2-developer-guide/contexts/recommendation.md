# Recommendation

`src/Recommendation/` generates music recommendations using multiple algorithms and stores them for retrieval. It does not own the entities it recommends (artists, genres) — those live in Catalog. This context is purely about computing, persisting, and serving recommendation scores.

## Domain Models

### Aggregate Root: Recommendation

| Property | Description |
|----------|-------------|
| `id` | Internal `Uuid` identifier |
| `publicId` | External 21-character `PublicId` identifier |
| `name` | Originating strategy name (for example `collaborative`, `content`, `genre`, or `default`) |
| `sourceType` | Source entity type (`RecommendationType`) |
| `sourceId` | ID of the source entity |
| `targetType` | Target entity type (`RecommendationType`) |
| `targetId` | ID of the recommended target entity |
| `score` | Numeric relevance score from the originating strategy |
| `position` | Optional ordering position |
| `userId` | Optional `Uuid` of the user who receives the recommendation |
| `createdAt` | Creation timestamp |
| `updatedAt` | Last update timestamp |

### Aggregate Root: RecommendationJob

`RecommendationJob` tracks an asynchronous or foreground recommendation generation run through a mutable `RecommendationJobState` state object (`id`, `publicId`, `isFull`, `userId`, `status`, `totalSongs`, `completedSongs`, `currentStrategy`, `strategyCounts`, `failReason`, `startedAt`, `completedAt`, `createdAt`, `updatedAt`, `metadata`, `originalJobId`, `originalJobPublicId`).

### Value Objects

| Type | Kind | Description |
|------|------|-------------|
| `RecommendationType` | `final readonly class` | Validated entity type (`song`, `album`, `artist`, `movie`, `video`) |
| `RecommendationJobStatus` | Enum | Lifecycle status of a recommendation job: `Pending` (`pending`, queued), `InProgress` (`in_progress`, running), `Completed` (`completed`), `Failed` (`failed`), `Cancelled` (`cancelled`) |

## Domain Services

Each domain service implements one recommendation algorithm:

| Service | Algorithm | Description |
|---------|-----------|-------------|
| `CollaborativeFilteringCalculator` | User-based collaborative filtering | Finds users with similar taste and recommends what they liked |
| `ContentSimilarityCalculator` | Content-based similarity | Matches entities with overlapping attributes |
| `DatabaseRelationCalculator` | Relationship-based | Uses existing database relations (e.g., same artist, same genre) |
| `GenreSimilarityCalculator` | Genre overlap scoring | Scores entities by shared genre membership |

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `SaveRecommendationCommand` | `SaveRecommendationHandler` | Persist a single computed recommendation |
| `GenerateRecommendationsCommand` | `GenerateRecommendationsHandler` | Run recommendation generation (dispatched by the console command, the admin action and the scheduler) |
| `ExecuteRecommendationJobCommand` | `ExecuteRecommendationJobHandler` | Execute a queued recommendation job on the `async` Messenger transport |
| `CancelRecommendationJobCommand` | `CancelRecommendationJobHandler` | Cancel a pending or in-progress recommendation job |
| `RequeueRecommendationJobCommand` | `RequeueRecommendationJobHandler` | Create and dispatch a new recommendation job from a failed or cancelled job |
| `DeleteRecommendationCommand` | `DeleteRecommendationHandler` | Remove one recommendation by UUID |
| `DeleteRecommendationsBySourceCommand` | `DeleteRecommendationsBySourceHandler` | Remove all recommendations originating from a source entity |

## Scheduled Generation

`GenerateRecommendationsCommand` implements the Scheduler's `SchedulableCommandInterface`, with the scheduler parameters `mode` (`full` or `incremental`) and `automatic`. The initial migration seeds an active scheduled job, **Generate recommendations**, that dispatches it daily at 04:00 UTC with `{"mode":"incremental","automatic":true}`.

The handler reads the system setting `recommendations.auto_generate` (off by default, contributed by `RecommendationSettingDefinitions`) when a command with `automatic: true` arrives. While the setting is off, the handler logs at info level and returns `skipped: recommendations.auto_generate is off`, which the scheduler records as the job's last result. Commands without `automatic`, from `app:recommendation:generate`, the admin action or a schedule an administrator creates, always generate. An unknown mode throws instead of falling back to incremental.

Unless `$command->foreground` is `true` (used by the CLI commands `app:recommendation:generate` and `app:recommendation:job:requeue`), `GenerateRecommendationsHandler` creates a `RecommendationJob` and dispatches `ExecuteRecommendationJobCommand` to the `async` Messenger transport (`ExecuteRecommendationJobHandler`). An incremental run loads the songs updated in the last seven days; a full run loads every song. Both load every user's listening history, which is why the seeded scheduled job is incremental.

## Queries & Query Handlers

| Query | Handler | Purpose |
|-------|---------|---------|
| `GetRecommendationQuery` | `GetRecommendationHandler` | Fetch a single recommendation by UUID |
| `GetRecommendationsForUserQuery` | `GetRecommendationsForUserHandler` | All recommendations for the authenticated user |
| `GetPersonalizedRecommendationsQuery` | `GetPersonalizedRecommendationsHandler` | Aggregated "For You" recommendations with explanations and per-strategy scores |
| `GetRecommendationsBySourceQuery` | `GetRecommendationsBySourceHandler` | Recommendations generated by a specific source entity |
| `GetTargetingRecommendationsQuery` | `GetTargetingRecommendationsHandler` | Recommendations targeting a specific entity type and ID |
| `GetRecommendationJobQuery` | `GetRecommendationJobHandler` | Fetch a single recommendation job by `publicId` |
| `ListRecommendationJobsQuery` | `ListRecommendationJobsHandler` | List recent recommendation jobs, optionally filtered by status |

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `RecommendationRepositoryInterface` | Domain repository contract for `Recommendation` persistence and queries | `RecommendationRepository` |
| `RecommendationJobRepositoryInterface` | Domain repository contract for `RecommendationJob` persistence and queries | `RecommendationJobRepository` |
| `RecommendationInsightsPortInterface` | Read access to recommendation coverage, source quality, and freshness metrics | `RecommendationInsightsRepository` |
| `RecommendationJobPortInterface` | Recommendation job lifecycle operations | `RecommendationJobService` |
| `RecommendationPublicIdentityInterface` | Resolves and projects public and internal identifiers for recommendations and catalog entities | `RecommendationPublicIdentity` |

## API Endpoints

### User Endpoints

User endpoints live under `RecommendationController` with the `/api/recommendations` prefix.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/recommendations/` | List recommendations for the current user (`limit` query parameter, max 200, default 50) |
| GET | `/api/recommendations/for-you` | Personalized recommendations with explanation breakdown (`RecommendationController::forYou`, `limit` max 100) |
| GET | `/api/recommendations/source/{sourceType}/{sourceId}` | Recommendations by source entity |
| GET | `/api/recommendations/targeting/{targetType}/{targetId}` | Recommendations targeting a specific entity |
| POST | `/api/recommendations/` | Create a recommendation |
| DELETE | `/api/recommendations/{publicId}` | Delete a single recommendation by `publicId` |
| DELETE | `/api/recommendations/source/{sourceType}/{sourceId}` | Delete all recommendations for a source |

### Admin Endpoints

Admin endpoints live under `RecommendationAdminController` with the `/api/admin/recommendations` prefix and require `ROLE_ADMIN`.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/recommendations/coverage` | Recommendation coverage statistics |
| GET | `/api/admin/recommendations/source-quality` | Recommendation source quality breakdown |
| GET | `/api/admin/recommendations/freshness` | Recommendation freshness metrics |
| POST | `/api/admin/recommendations/generate` | Trigger recommendation generation |
| GET | `/api/admin/recommendations/jobs` | List recent recommendation jobs |
| GET | `/api/admin/recommendations/jobs/{publicId}` | Get recommendation job status |
| POST | `/api/admin/recommendations/jobs/{publicId}/requeue` | Requeue a failed or cancelled recommendation job |
| DELETE | `/api/admin/recommendations/jobs/{publicId}` | Cancel a pending or in-progress recommendation job |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, and `SystemSettingsPortInterface` for `recommendations.auto_generate` |
| Depends on | Activity | `ActivityPortInterface` for user listening histories |
| Depends on | Auth | `AuthenticatedUserIdentityInterface` for authenticated user resolution |
| Depends on | Catalog | Song, artist, and genre references (read-only) |
| Depends on | Library | `LibraryReadScopeProviderInterface` for scoping visible catalog entities |
| Depends on | Scheduler | `GenerateRecommendationsCommand` is schedulable through the `Scheduler Schedulable Contract` Deptrac layer |
| Depended on by | — | No contexts depend on Recommendation directly |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `RecommendationEntity` | Doctrine entity | ORM entity mapped to the `recommendations` table |
| `RecommendationJobEntity` | Doctrine entity | ORM entity mapped to the `recommendation_jobs` table |
| `RecommendationRepository` | Doctrine repository | Implements `RecommendationRepositoryInterface` |
| `RecommendationJobRepository` | Doctrine repository | Implements `RecommendationJobRepositoryInterface` |
| `RecommendationInsightsRepository` | Doctrine repository | Implements `RecommendationInsightsPortInterface` |
| `RecommendationJobService` | Port implementation | Implements `RecommendationJobPortInterface` with row-locked status transitions |
| `RecommendationPublicIdentity` | Port implementation | Implements `RecommendationPublicIdentityInterface` |
| `RecommendationMessagePayloadCodec` | Messenger codec | Encodes and decodes `ExecuteRecommendationJobCommand` on the `async` transport |
| `RecommendationJobCleanupService` | Swoole listener | Marks orphaned `pending` and stale `in_progress` recommendation jobs as `failed` on server start |

### Console Commands

| Command | Purpose |
|---------|---------|
| `app:recommendation:generate` (`RecommendationGenerateCommand`) | Generate music recommendations with every strategy in the foreground process |
| `app:recommendation:stats` (`RecommendationStatsCommand`) | Show recommendation coverage, source quality, and freshness |
| `app:recommendation:job:list` (`RecommendationJobListCommand`) | List the most recent recommendation jobs, newest first |
| `app:recommendation:job:show` (`RecommendationJobShowCommand`) | Show one recommendation job's status, progress, counts, and metadata |
| `app:recommendation:job:cancel` (`RecommendationJobCancelCommand`) | Cancel a pending or running recommendation job |
| `app:recommendation:job:requeue` (`RecommendationJobRequeueCommand`) | Requeue a failed or cancelled recommendation job and run the new job in the foreground process |
