# Recommendation

`src/Recommendation/` generates music recommendations with several algorithms and stores them for retrieval. The songs, albums, artists, movies and videos it recommends live in Catalog; this context computes, persists and serves recommendation scores.

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
| `origin` | `RecommendationOrigin`: `generator` for a row the generator wrote, `member` for one a user wrote through `POST /api/recommendations/` |
| `createdAt` | Creation timestamp |
| `updatedAt` | Last update timestamp |

### Aggregate Root: RecommendationJob

`RecommendationJob` tracks an asynchronous or foreground recommendation generation run through a mutable `RecommendationJobState` state object (`id`, `publicId`, `isFull`, `userId`, `status`, `totalSongs`, `completedSongs`, `currentStrategy`, `strategyCounts`, `failReason`, `startedAt`, `completedAt`, `createdAt`, `updatedAt`, `metadata`, `originalJobId`, `originalJobPublicId`).

### Value Objects

| Type | Kind | Description |
|------|------|-------------|
| `RecommendationType` | `final readonly class` | Validated entity type (`song`, `album`, `artist`, `movie`, `video`) |
| `RecommendationOrigin` | Enum | Who wrote a recommendation: `Generator` (`generator`) or `Member` (`member`) |
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
| `SaveRecommendationCommand` | `SaveRecommendationHandler` | Store a single computed recommendation unless a row with its source, target, name and user is stored, and report whether it stored one |
| `CreateRecommendationCommand` | `CreateRecommendationHandler` | Store a recommendation a user wrote through the API, under the per-user cap |
| `GenerateRecommendationsCommand` | `GenerateRecommendationsHandler` | Run recommendation generation (dispatched by the console command, the admin action and the scheduler) |
| `ExecuteRecommendationJobCommand` | `ExecuteRecommendationJobHandler` | Execute a queued recommendation job on the `async` Messenger transport |
| `CancelRecommendationJobCommand` | `CancelRecommendationJobHandler` | Cancel a pending or in-progress recommendation job |
| `RequeueRecommendationJobCommand` | `RequeueRecommendationJobHandler` | Create and dispatch a new recommendation job from a failed or cancelled job |
| `DeleteRecommendationCommand` | `DeleteRecommendationHandler` | Remove one recommendation by UUID |
| `DeleteRecommendationsBySourceCommand` | `DeleteRecommendationsBySourceHandler` | Remove the `member` rows a user wrote from a source entity, or, without a user, every row from it |
| `DeleteGeneratedRecommendationsBySourceCommand` | `DeleteGeneratedRecommendationsBySourceHandler` | Before a run writes, remove the `generator` rows from a source entity that the run writes anew: those of the run's user and those of no user under the shared names |

## Scheduled Generation

`GenerateRecommendationsCommand` implements the Scheduler's `SchedulableCommandInterface`, with the scheduler parameters `mode` (`full` or `incremental`) and `automatic`. The initial migration seeds an active scheduled job, **Generate recommendations**, that dispatches it daily at 04:00 UTC with `{"mode":"incremental","automatic":true}`.

The handler reads the system setting `recommendations.auto_generate` (off by default, contributed by `RecommendationSettingDefinitions`) when a command with `automatic: true` arrives. While the setting is off, the handler logs at info level and returns `skipped: recommendations.auto_generate is off`, which the scheduler records as the job's last result. Commands without `automatic`, from `app:recommendation:generate`, the admin action or a schedule an administrator creates, always generate. An unknown mode throws instead of falling back to incremental.

Unless `$command->foreground` is `true` (used by the CLI commands `app:recommendation:generate` and `app:recommendation:job:requeue`), `GenerateRecommendationsHandler` creates a `RecommendationJob` and dispatches `ExecuteRecommendationJobCommand` to the `async` Messenger transport (`ExecuteRecommendationJobHandler`). An incremental run loads the songs updated in the last seven days; a full run loads every song. Both load every user's listening history, which is why the seeded scheduled job is incremental.

A run replaces only the generator's rows. Before writing, it deletes each song's `generator` rows that it writes anew: the run's user's rows, the `content` and `genre` rows of no user, and, only in a run with no user, the `collaborative` rows of no user. A run for one user therefore keeps the shared `collaborative` rows that every member reads, and `member` rows stay. The run writes each row with `ON CONFLICT DO NOTHING` on `uniq_recommendations_source_target_name_user_id`, so a row already stored under the key stays and the run goes on. That row may be a member's, such as the member's own `collaborative` row in a run for that member, or one an overlapping run stored first. The run completes, and its per-strategy counts include only the rows it stored.

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

The source and targeting queries carry the viewer. For a member, the repository keeps the viewer's rows and the rows that belong to no user before it applies the limit, so another user's rows cannot fill the page; an administrator reads every user's rows. Library visibility of each row's source and target is checked after the query, so a page can hold fewer rows than the limit.

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `RecommendationRepositoryInterface` | Domain repository contract for `Recommendation` persistence and queries | `RecommendationRepository` |
| `RecommendationJobRepositoryInterface` | Domain repository contract for `RecommendationJob` persistence and queries | `RecommendationJobRepository` |
| `RecommendationInsightsPortInterface` | Read access to recommendation coverage, source quality, and freshness metrics | `RecommendationInsightsRepository` |
| `RecommendationJobPortInterface` | Recommendation job lifecycle operations | `RecommendationJobService` |
| `RecommendationPublicIdentityInterface` | Resolves and projects public and internal identifiers for recommendations and catalog entities; resolves the public ID of the user that a job's `triggered_by` or `requeued_by` metadata names | `RecommendationPublicIdentity` |
| `ArtistRecommendationMergePortInterface` | Published contract for Catalog's artist merge (the `Recommendation Artist Merge Contract` Deptrac layer): inside the caller's transaction, points the recommendations from or to a merged artist at the target, deleting any that would recommend the target to itself or duplicate one it has, or deletes every recommendation from or to a removed artist | `ArtistRecommendationMerge` |

## API Endpoints

### User Endpoints

User endpoints live under `RecommendationController` with the `/api/recommendations` prefix.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/recommendations/` | List recommendations for the current user (`limit` query parameter, max 200, default 50) |
| GET | `/api/recommendations/for-you` | Personalized recommendations with explanation breakdown (`RecommendationController::forYou`, `limit` max 100) |
| GET | `/api/recommendations/source/{sourceType}/{sourceId}` | Recommendations by source entity |
| GET | `/api/recommendations/targeting/{targetType}/{targetId}` | Recommendations targeting a specific entity |
| POST | `/api/recommendations/` | Create a recommendation (see below) |
| DELETE | `/api/recommendations/{publicId}` | Delete a single recommendation by `publicId` |
| DELETE | `/api/recommendations/source/{sourceType}/{sourceId}` | Delete the rows from a source the member wrote through the API, or every row from it for an administrator |

`POST /api/recommendations/` binds its body to `CreateRecommendationRequest`, whose bounds live in `RecommendationLimits`. Both types are one of `song`, `album`, `artist`, `movie` and `video`; `score` is a finite number from 0 to 1, the range the generator scores in; `name` has at most 255 characters and defaults to `default`; `position` fits the 32-bit `position` column. A malformed body answers 422. A source or target ID that is not the public ID of an entity the user can see answers 404.

`CreateRecommendationHandler` caps the rows a user writes through the API. Under a transaction-scoped advisory lock on the user, it counts the user's `member` rows and refuses the 1,001st with 409 (`reason` `recommendation_limit`, `limit` 1000), and it refuses a row with the same source, target, name and user with 409 (`reason` `recommendation_exists`). The generator's rows do not count, including the `collaborative` rows of a run for one user, and the generator writes past the cap. The partial index `idx_recommendations_user_id_member` serves the count. In the database, `chk_recommendations_name_length` bounds the name length; the bound-repair migration deleted the rows whose name exceeded the bound, then validated the check.

A stored recommendation answers 201 with the row as a `RecommendationResource` under `data`. When the user loses access to the source or target between the write and the answer, the answer still names them by the public IDs the request sent.

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
| Depended on by | Catalog | `ArtistMergeService` moves or deletes an artist's recommendations through `ArtistRecommendationMergePortInterface` |

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
