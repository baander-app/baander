# Admin Panel Integration Plan

**Requirements:** `docs/brainstorms/2026-05-11-admin-panel-integration-requirements.md`
**Date:** 2026-05-11

## Problem Summary

The backend has 14 bounded contexts with extensive API surface. The admin panel only surfaces infrastructure monitoring (jobs, diagnostics, rate limiters, config). All operational and content management capabilities require CLI or direct API calls. Additionally, the current two-role system (USER/ADMIN) needs to become three-tier (USER/ADMIN/SUPER_ADMIN) with library-level access grants.

## Relevant Learnings

No prior solutions found in `docs/solutions/` or `~/.pi/agent/docs/solutions/`.

Key project patterns to follow:
- **DDD Port pattern:** Controller → Port Interface → Infrastructure implementation
- **State-object aggregates:** Private constructor, `create()`, `reconstitute()` with `{Entity}State`
- **CQRS:** `final readonly class` commands, `#[AsMessageHandler]` handlers, dispatched via `MessageBusInterface`
- **Testing:** Functional tests extend `tests/Functional/TestCase`, use `authenticatedRequest()` with `X-Test-User-Id` header, manual object construction (no factories)
- **Frontend:** `api/` → `hooks/` → `components/` → `pages/` per feature, shadcn components, `AXIOS_INSTANCE` for HTTP

## Scope Boundaries

**In scope:**
- Three-tier role system (SUPER_ADMIN/ADMIN/USER)
- Library permission model (grant/revoke per user per library)
- System settings backend + UI
- Admin user management (full CRUD)
- All admin frontend pages for existing backend endpoints
- New admin-specific backend endpoints (activity analytics, recommendation insights, lyrics admin)
- Sidebar redesign (grouped, collapsible)
- Enhanced diagnostics page (coroutines, workers, spans)

**Out of scope:**
- Admin audit logging
- Email template editor
- Notification management page (deferred to future work)
- Multi-tenant data isolation

## Implementation Units

---

### Unit 1: Three-tier Role System (Backend)

**Goal:** Add `ROLE_SUPER_ADMIN` to the role hierarchy so the system supports SUPER_ADMIN → ADMIN → USER. Update all security configuration and domain constraints.

**Files:**
- `src/Auth/Domain/Model/User.php` — add `ROLE_SUPER_ADMIN` to `ROLE_HIERARCHY`
- `src/Auth/Application/CommandHandler/User/CreateUserHandler.php` — add `ROLE_SUPER_ADMIN` to `ALLOWED_ROLES`
- `src/Auth/Infrastructure/Security/Voter/AdminVoter.php` — add `USER_MANAGEMENT` and `SYSTEM_SETTINGS` attributes with role checks
- `config/packages/security.yaml` — add `ROLE_SUPER_ADMIN: ROLE_ADMIN` to `role_hierarchy`, add `/api/admin/*` access_control
- `tests/Functional/Security/RoleHierarchyTest.php` — NEW: verify role hierarchy and voter behavior
- `tests/Unit/Auth/Infrastructure/Security/Voter/AdminVoterTest.php` — update existing test for new attributes

**Patterns to follow:**
- `AdminVoter` pattern: `supports()` checks attribute string, `voteOnAttribute()` checks `$token->getRoleNames()`
- `security.yaml` role_hierarchy map
- Existing `AdminVoterTest` for unit test pattern

**Test scenarios:**
- SUPER_ADMIN has ROLE_ADMIN and ROLE_USER resolved by hierarchy
- ADMIN has ROLE_USER resolved by hierarchy
- `ADMIN_ACCESS` attribute grants for both SUPER_ADMIN and ADMIN
- `USER_MANAGEMENT` attribute grants for SUPER_ADMIN only
- `SYSTEM_SETTINGS` attribute grants for SUPER_ADMIN only
- `CreateUserHandler` accepts `ROLE_SUPER_ADMIN` as valid role

**Verification:**
```bash
make phpunit tests/Unit/Auth/Infrastructure/Security/Voter/AdminVoterTest.php
make phpunit tests/Functional/Security/RoleHierarchyTest.php
```

**Dependencies:** None (foundation unit).

---

### Unit 2: System Settings Backend

**Goal:** Create a key-value system settings store with CRUD endpoints. SUPER_ADMIN can read/write settings. ADMIN can read.

**Files:**
- `migrations/` — new migration: `system_settings` table (key TEXT PK, value JSONB, updated_at TIMESTAMP)
- `src/Shared/Domain/Model/SystemSetting.php` — NEW: simple domain model (not an aggregate root)
- `src/Shared/Infrastructure/Doctrine/Entity/SystemSettingEntity.php` — NEW: Doctrine entity
- `src/Shared/Infrastructure/Doctrine/Repository/SystemSettingRepository.php` — NEW
- `src/Shared/Application/Port/SystemSettingsPortInterface.php` — NEW: `get(string $key): mixed`, `set(string $key, mixed $value): void`, `all(): array`
- `src/Shared/Interface/Controller/SystemSettingsController.php` — NEW: `GET /api/admin/settings`, `PATCH /api/admin/settings`
- `config/services.yaml` — wire port alias
- `tests/Functional/Controller/SystemSettingsControllerTest.php` — NEW
- `tests/Unit/Shared/Domain/Model/SystemSettingTest.php` — NEW

**Patterns to follow:**
- Port pattern from `.claude/rules/ddd-ports.md`
- Controller pattern: `ApiResponsesTrait`, `#[Route]`, `#[OA\*]` annotations
- Test pattern: `tests/Functional/TestCase`, `authenticatedRequest()`
- Column types: TEXT for strings, JSONB for JSON (per CLAUDE.md rules)

**Test scenarios:**
- GET settings returns empty array when no settings exist
- PATCH settings creates and updates key-value pairs
- PATCH settings validates value is valid JSONB
- ADMIN can GET but cannot PATCH (403)
- USER gets 403 on both GET and PATCH
- Settings persist across requests

**Verification:**
```bash
make phpunit tests/Functional/Controller/SystemSettingsControllerTest.php
make phpunit tests/Unit/Shared/Domain/Model/SystemSettingTest.php
```

**Dependencies:** Unit 1 (role hierarchy must exist for access control).

---

### Unit 3: Library Permission Model (Backend)

**Goal:** Create the `user_library_access` join table and grant/revoke/query endpoints. SUPER_ADMIN has implicit access to all libraries.

**Files:**
- `migrations/` — new migration: `user_library_access` table
- `src/Library/Infrastructure/Doctrine/Entity/UserLibraryAccessEntity.php` — NEW: Doctrine entity for join table
- `src/Library/Infrastructure/Doctrine/Repository/LibraryAccessRepository.php` — NEW
- `src/Library/Application/Port/LibraryAccessPortInterface.php` — NEW: `grant(Uuid $userId, Uuid $libraryId): void`, `revoke(Uuid $userId, Uuid $libraryId): void`, `getUserLibraries(Uuid $userId): array`, `hasAccess(Uuid $userId, Uuid $libraryId): bool`
- `src/Auth/Interface/Controller/AdminUserController.php` — NEW (partial: library access endpoints only)
  - `GET /api/admin/users/{id}/library-access`
  - `POST /api/admin/users/{id}/library-access`
  - `DELETE /api/admin/users/{id}/library-access/{libraryId}`
- `config/services.yaml` — wire port alias
- `tests/Functional/Controller/LibraryAccessTest.php` — NEW
- `tests/Unit/Library/Application/Port/LibraryAccessPortInterfaceTest.php` — NEW (contract test)

**Patterns to follow:**
- Repository pattern from `.claude/rules/ddd-repositories.md`
- UUID primary keys per CLAUDE.md
- Port pattern for `LibraryAccessPortInterface`

**Test scenarios:**
- Grant access: creates row, returns 200
- Grant duplicate: idempotent (no error)
- Revoke access: deletes row, returns 200
- Revoke non-existent: returns 200 (idempotent)
- Get user libraries: returns list of library IDs
- Has access: returns true/false correctly
- SUPER_ADMIN implicit: not checked at this layer (handled in voter/application)
- Non-SUPER_ADMIN user gets 403 on all endpoints

**Verification:**
```bash
make phpunit tests/Functional/Controller/LibraryAccessTest.php
```

**Dependencies:** Unit 1 (role hierarchy).

---

### Unit 4: Admin User Management Backend

**Goal:** Full CRUD admin controller for user management. List, create, edit, delete, role assignment, password reset, disable/enable.

**Files:**
- `src/Auth/Interface/Controller/AdminUserController.php` — NEW or extend from Unit 3
  - `GET /api/admin/users` — list (paginated, filterable by role/disabled)
  - `POST /api/admin/users` — create
  - `PATCH /api/admin/users/{id}` — update name/email
  - `DELETE /api/admin/users/{id}` — delete
  - `POST /api/admin/users/{id}/roles` — assign roles
  - `POST /api/admin/users/{id}/reset-password` — reset password
  - `POST /api/admin/users/{id}/disable` — disable
  - `POST /api/admin/users/{id}/enable` — enable
- `src/Auth/Interface/Request/Admin/` — NEW: request DTOs
  - `AdminCreateUserRequest.php`
  - `AdminUpdateUserRequest.php`
  - `AdminAssignRolesRequest.php`
  - `AdminResetPasswordRequest.php`
- `src/Auth/Interface/Resource/AdminUserResource.php` — NEW: extended user resource with admin fields (roles, disabled, library access)
- `src/Auth/Application/Port/UserPortInterface.php` — add missing query methods if needed (`findByRole`, `countByRole`, paginated list)
- `tests/Functional/Controller/AdminUserControllerTest.php` — NEW

**Patterns to follow:**
- Controller + request DTO + resource pattern from `.claude/rules/ddd-ports.md`
- `ApiResponsesTrait`, `TranslatorTrait`
- `#[MapRequestPayload]` for request validation
- `#[IsGranted('ROLE_SUPER_ADMIN')]` on write endpoints
- Existing `AuthController` for request/response patterns
- Existing `UserResource` for resource pattern

**Test scenarios:**
- List users: returns paginated results with admin fields
- List users with role filter: returns only matching users
- Create user: persists with specified role, returns 201
- Create user with duplicate email: returns 409
- Update user name: persists, returns updated resource
- Assign roles: SUPER_ADMIN can assign any role, persists correctly
- Assign roles as ADMIN: returns 403
- Reset password: changes password, user can login with new password
- Disable user: user cannot authenticate
- Enable user: user can authenticate again
- Delete user: user is removed, returns 204
- All endpoints: USER gets 403, ADMIN gets 403 (unless system setting `admin.can_view_users` is true, then read-only)

**Verification:**
```bash
make phpunit tests/Functional/Controller/AdminUserControllerTest.php
```

**Dependencies:** Unit 1, Unit 2 (system settings for `admin.can_view_users` toggle), Unit 3 (library access endpoints in same controller).

---

### Unit 5: TestAuthenticator Role Fix

**Goal:** Fix the `TestAuthenticator` to pass user roles to `SecurityUser`, so role-based tests actually test real behavior.

**Files:**
- `tests/Functional/Security/TestAuthenticator.php` — pass `$user->getRoles()` to `SecurityUser` constructor

**Patterns to follow:**
- Real `PasswordAuthenticator` already passes roles correctly

**Test scenarios:**
- Create test user with `ROLE_ADMIN`, make request → verify `$token->getRoleNames()` includes `ROLE_ADMIN`
- Create test user with `ROLE_SUPER_ADMIN` → verify hierarchy resolution

**Verification:**
```bash
make phpunit tests/Functional/Security/RoleHierarchyTest.php
```

**Dependencies:** Unit 1 (new roles must exist).

---

### Unit 6: Admin Activity Analytics Backend

**Goal:** Admin-facing analytics endpoints for listening activity: summary stats, top tracks, user engagement.

**Files:**
- `src/Activity/Application/Port/ActivityAnalyticsPortInterface.php` — NEW: `getSummary()`, `getTopTracks(DateTimeInterface $from, DateTimeInterface $to, int $limit)`, `getEngagement()`
- `src/Activity/Infrastructure/ActivityAnalyticsService.php` — NEW: implements port with DB queries
- `src/Activity/Interface/Controller/AdminActivityController.php` — NEW
  - `GET /api/admin/activity/summary`
  - `GET /api/admin/activity/top-tracks`
  - `GET /api/admin/activity/engagement`
- `config/services.yaml` — wire port alias
- `tests/Functional/Controller/AdminActivityControllerTest.php` — NEW

**Patterns to follow:**
- Port pattern, controller pattern
- Existing `ActivityController` for context structure
- Use raw DQL or SQL for aggregation queries (no ORM magic)

**Test scenarios:**
- Summary returns total plays, unique listeners, active users count
- Top tracks returns sorted by play count within time range
- Engagement returns per-user play counts
- Empty database returns zeroed stats (not errors)
- ADMIN can access, USER gets 403

**Verification:**
```bash
make phpunit tests/Functional/Controller/AdminActivityControllerTest.php
```

**Dependencies:** Unit 1.

---

### Unit 7: Admin Recommendation Insights Backend

**Goal:** Admin-facing endpoints for recommendation coverage and source quality.

**Files:**
- `src/Recommendation/Application/Port/RecommendationInsightsPortInterface.php` — NEW
- `src/Recommendation/Infrastructure/RecommendationInsightsService.php` — NEW
- `src/Recommendation/Interface/Controller/AdminRecommendationController.php` — NEW
  - `GET /api/admin/recommendations/coverage`
  - `GET /api/admin/recommendations/sources`
- `config/services.yaml` — wire
- `tests/Functional/Controller/AdminRecommendationControllerTest.php` — NEW

**Patterns to follow:** Same as Unit 6.

**Test scenarios:**
- Coverage returns % of catalog with recommendations per source type
- Sources returns per-source count and quality metrics
- Empty database returns zeroed stats

**Verification:**
```bash
make phpunit tests/Functional/Controller/AdminRecommendationControllerTest.php
```

**Dependencies:** Unit 1.

---

### Unit 8: Admin Lyrics Backend

**Goal:** Admin endpoints for bulk lyrics operations and coverage stats.

**Files:**
- `src/Lyrics/Application/Port/LyricsAdminPortInterface.php` — NEW: `triggerBulkFetch()`, `getCoverage()`
- `src/Lyrics/Infrastructure/LyricsAdminService.php` — NEW
- `src/Lyrics/Interface/Controller/AdminLyricsController.php` — NEW
  - `POST /api/admin/lyrics/bulk-fetch`
  - `GET /api/admin/lyrics/coverage`
- `config/services.yaml` — wire
- `tests/Functional/Controller/AdminLyricsControllerTest.php` — NEW

**Patterns to follow:** Same as Unit 6.

**Test scenarios:**
- Bulk fetch dispatches job, returns job ID
- Coverage returns % of songs with lyrics, per-source breakdown
- Empty database returns zeroed stats

**Verification:**
```bash
make phpunit tests/Functional/Controller/AdminLyricsControllerTest.php
```

**Dependencies:** Unit 1.

---

### Unit 9: Frontend — Admin Sidebar Redesign

**Goal:** Rewrite `AdminSidebar` with grouped collapsible sections. Update `AdminRoute` for three-tier roles. Conditionally show/hide sidebar groups based on role and system settings.

**Files:**
- `ui/web/src/features/admin/components/AdminSidebar.tsx` — REWRITE: grouped collapsible sections
- `ui/web/src/features/admin/components/AdminRoute.tsx` — UPDATE: role check (SUPER_ADMIN or ADMIN)
- `ui/web/src/features/admin/api/system-settings-api.ts` — NEW
- `ui/web/src/features/admin/hooks/use-system-settings.ts` — NEW
- `ui/web/src/features/layout/routes.tsx` — UPDATE: add all new admin routes

**Patterns to follow:**
- Current `AdminSidebar` uses `NavLink`, `lucide-react` icons, `w-52` sidebar
- Design language: flat surfaces, sparse borders, `text-[13px]`, `text-muted-foreground`
- Existing route structure in `routes.tsx`

**Test scenarios:**
- SUPER_ADMIN sees all groups
- ADMIN sees all groups except Users (unless system setting enabled)
- ADMIN never sees System Settings link
- USER redirected to `/` (existing behavior)
- Sidebar groups collapse/expand
- Active item highlighted correctly
- All new routes registered and resolve

**Verification:**
```bash
cd ui/web && yarn test
cd ui/web && yarn build
```

**Dependencies:** Unit 2 (system settings API must exist).

---

### Unit 10: Frontend — User Management Page

**Goal:** Full CRUD user management page with table view, create/edit modals, role assignment, library permission management.

**Files:**
- `ui/web/src/features/admin/api/user-admin-api.ts` — NEW
- `ui/web/src/features/admin/hooks/use-user-admin.ts` — NEW
- `ui/web/src/features/admin/components/UserTable.tsx` — NEW: virtualized list
- `ui/web/src/features/admin/components/UserFormDialog.tsx` — NEW: create/edit
- `ui/web/src/features/admin/components/RoleSelector.tsx` — NEW
- `ui/web/src/features/admin/components/LibraryAccessManager.tsx` — NEW
- `ui/web/src/features/admin/pages/UserManagementPage.tsx` — NEW

**Patterns to follow:**
- `JobTable` for virtualized list pattern
- `JobDetailPanel` for slide-out detail pattern
- shadcn `Dialog`, `Button`, `Select`
- DESIGN.md: `text-sm` body, `text-[11px]` labels, monospace for IDs/timestamps

**Test scenarios:**
- Page loads user list from API
- Create user form validates and submits
- Edit user form pre-fills and updates
- Role selector shows three options
- Library access manager shows grant/revoke per library
- Disable/enable toggle works
- Delete confirmation dialog
- Pagination works
- Role filter works

**Verification:**
```bash
cd ui/web && yarn test
cd ui/web && yarn build
```

**Dependencies:** Unit 4 (backend CRUD endpoints), Unit 9 (sidebar/routing).

---

### Unit 11: Frontend — System Settings Page

**Goal:** Settings page with grouped toggles, labels, descriptions for SUPER_ADMIN.

**Files:**
- `ui/web/src/features/admin/components/SettingsGroup.tsx` — NEW
- `ui/web/src/features/admin/components/SettingsToggle.tsx` — NEW
- `ui/web/src/features/admin/pages/SystemSettingsPage.tsx` — NEW

**Patterns to follow:**
- `DevPanelToggle` in `ServerDiagnosticsPage` for toggle switch pattern
- Card-based section layout

**Test scenarios:**
- Page loads settings from API
- Toggle switch updates setting via PATCH
- Settings grouped by category
- Non-SUPER_ADMIN redirected

**Verification:**
```bash
cd ui/web && yarn test
cd ui/web && yarn build
```

**Dependencies:** Unit 2 (backend), Unit 9 (routing).

---

### Unit 12: Frontend — Metadata Admin Page

**Goal:** Admin page for metadata operations: trigger syncs, search external APIs, browse MusicBrainz data, batch cover extraction.

**Files:**
- `ui/web/src/features/admin/api/metadata-admin-api.ts` — NEW
- `ui/web/src/features/admin/hooks/use-metadata-admin.ts` — NEW
- `ui/web/src/features/admin/components/MetadataSearchForm.tsx` — NEW
- `ui/web/src/features/admin/components/MetadataResults.tsx` — NEW
- `ui/web/src/features/admin/components/CoverExtractionPanel.tsx` — NEW
- `ui/web/src/features/admin/pages/MetadataPage.tsx` — NEW

**Patterns to follow:**
- Calls existing endpoints: `/api/metadata/search/*`, `/api/metadata/extract`, `/api/metadata/match`, `/api/albums/covers/extract`

**Test scenarios:**
- Search artist/album/song returns results from external APIs
- Extract metadata triggers job
- Match metadata shows results
- Cover extraction triggers batch job

**Verification:**
```bash
cd ui/web && yarn test && yarn build
```

**Dependencies:** Unit 9 (routing). No new backend needed — uses existing endpoints.

---

### Unit 13: Frontend — Lyrics Admin Page

**Goal:** Admin page for lyrics: bulk fetch trigger, coverage stats.

**Files:**
- `ui/web/src/features/admin/api/lyrics-admin-api.ts` — NEW
- `ui/web/src/features/admin/hooks/use-lyrics-admin.ts` — NEW
- `ui/web/src/features/admin/components/LyricsCoverageChart.tsx` — NEW
- `ui/web/src/features/admin/pages/LyricsAdminPage.tsx` — NEW

**Dependencies:** Unit 8 (backend), Unit 9 (routing).

---

### Unit 14: Frontend — Transcode Admin Page

**Goal:** Admin page for transcode job list, session management, cleanup trigger.

**Files:**
- `ui/web/src/features/admin/api/transcode-admin-api.ts` — NEW
- `ui/web/src/features/admin/hooks/use-transcode-admin.ts` — NEW
- `ui/web/src/features/admin/components/TranscodeJobTable.tsx` — NEW
- `ui/web/src/features/admin/components/TranscodeSessionTable.tsx` — NEW
- `ui/web/src/features/admin/pages/TranscodePage.tsx` — NEW

**Dependencies:** Unit 9. Uses existing `/api/transcode/*` endpoints.

---

### Unit 15: Frontend — Radio Admin Page

**Goal:** Admin page for station sources, country subscriptions, sync triggers.

**Files:**
- `ui/web/src/features/admin/api/radio-admin-api.ts` — NEW
- `ui/web/src/features/admin/hooks/use-radio-admin.ts` — NEW
- `ui/web/src/features/admin/components/RadioSourceList.tsx` — NEW
- `ui/web/src/features/admin/components/CountrySubscriptionList.tsx` — NEW
- `ui/web/src/features/admin/pages/RadioAdminPage.tsx` — NEW

**Dependencies:** Unit 9. Uses existing `/api/radio/*` endpoints.

---

### Unit 16: Frontend — Enhanced Diagnostics Page

**Goal:** Surface coroutines, workers, spans, and prometheus link in the existing diagnostics page.

**Files:**
- `ui/web/src/features/admin/api/debug-api.ts` — NEW (coroutines, workers, spans endpoints)
- `ui/web/src/features/admin/hooks/use-debug-stats.ts` — NEW
- `ui/web/src/features/admin/pages/ServerDiagnosticsPage.tsx` — UPDATE: add new sections

**Dependencies:** Unit 9. Uses existing `/api/debug/coroutines`, `/api/debug/workers`, `/api/debug/spans` endpoints.

---

### Unit 17: Frontend — Activity Analytics Page

**Goal:** Analytics dashboard showing play counts, top tracks, user engagement.

**Files:**
- `ui/web/src/features/admin/api/activity-admin-api.ts` — NEW
- `ui/web/src/features/admin/hooks/use-activity-admin.ts` — NEW
- `ui/web/src/features/admin/components/ActivitySummaryCards.tsx` — NEW
- `ui/web/src/features/admin/components/TopTracksTable.tsx` — NEW
- `ui/web/src/features/admin/components/UserEngagementTable.tsx` — NEW
- `ui/web/src/features/admin/pages/ActivityPage.tsx` — NEW

**Dependencies:** Unit 6 (backend), Unit 9 (routing).

---

### Unit 18: Frontend — Recommendation Insights Page

**Goal:** Insights page showing recommendation coverage, source quality.

**Files:**
- `ui/web/src/features/admin/api/recommendation-admin-api.ts` — NEW
- `ui/web/src/features/admin/hooks/use-recommendation-admin.ts` — NEW
- `ui/web/src/features/admin/components/CoverageChart.tsx` — NEW
- `ui/web/src/features/admin/components/SourceQualityTable.tsx` — NEW
- `ui/web/src/features/admin/pages/RecommendationsPage.tsx` — NEW

**Dependencies:** Unit 7 (backend), Unit 9 (routing).

---

### Unit 19: Frontend — Dashboard Enhancement (Operator Nerve Center)

**Goal:** Rewrite the dashboard as the operator's live nerve center. Not static cards — a living view of engine state.

**Sections:**
1. **Live Health Row** — PostgreSQL, Redis, Swoole, memory gauges. Green/red dots that update via SSE (not page reload). Reuses `HealthCheckService` data polled every 10s.
2. **Active Operations Feed** — running jobs, active transcode sessions, active library scans. Streamed via SSE. Each item shows progress percentage, elapsed time, and a link to the detail page.
3. **Alert History** — last 10 admin notifications (failed jobs, health changes, user registrations). Consumed from `/api/notifications/sse`. New alerts animate in.
4. **Quick Actions** — buttons: "Trigger Library Scan", "Retry All Failed Jobs", "Bulk Fetch Lyrics", "Run Health Check". Each dispatches the relevant API call and shows inline progress.
5. **Trend Sparklines** — small inline charts: jobs completed/failed over last 24h, memory usage trend, play count trend. Data from analytics endpoints.

**Files:**
- `ui/web/src/features/admin/pages/AdminDashboardPage.tsx` — REWRITE
- `ui/web/src/features/admin/hooks/use-dashboard-summary.ts` — NEW: aggregates data from multiple endpoints
- `ui/web/src/features/admin/hooks/use-admin-sse.ts` — NEW: multiplexed SSE hook (job events + notification events)
- `ui/web/src/features/admin/components/HealthIndicator.tsx` — NEW: green/red dot + label + response time
- `ui/web/src/features/admin/components/ActiveOperationsFeed.tsx` — NEW: live list of running operations
- `ui/web/src/features/admin/components/AlertHistory.tsx` — NEW: last N admin alerts
- `ui/web/src/features/admin/components/QuickActions.tsx` — NEW: action buttons with inline progress
- `ui/web/src/features/admin/components/TrendSparkline.tsx` — NEW: tiny SVG sparkline chart

**Patterns to follow:**
- SSE consumption via `useJobSse` pattern (fetch + ReadableStream)
- Design language: flat cards, `text-[11px]` labels, monospace for numbers/timestamps
- No chart libraries — hand-roll SVG sparklines (they're 20 lines of JSX)

**Test scenarios:**
- Dashboard renders health indicators for all 4 components
- SSE connection established, live updates reflected in health and operations
- Quick action buttons dispatch correct API calls
- Alert history populates from notification stream
- Sparklines render with empty data (graceful degradation)

**Verification:**
```bash
cd ui/web && yarn test && yarn build
```

**Dependencies:** Unit 9 (routing), Unit 20 (admin notification category for alerts), Unit 22 (live metrics SSE).

---

### Unit 20: Admin Notification Category

**Goal:** Add admin-targeted alerting to the existing Notification system. When things happen that operators need to know about, push notifications to all admin users.

**Events to alert on:**
- Job failed → `AdminOperations` notification to all admins
- Library scan completed → notification to triggering user
- Health degradation (Redis disconnect, memory spike) → notification to all admins
- New user registered → notification to all admins (if system setting enabled)

**Files:**
- `src/Notification/Domain/ValueObject/NotificationCategory.php` — add `AdminOperations` case
- `src/Notification/Domain/Service/EventCategoryResolver.php` — add admin event mappings
- `src/Shared/Application/Port/AdminAlertPortInterface.php` — NEW: `alertAdmins(string $title, string $body, string $eventType, ?string $link = null): void`
- `src/Shared/Infrastructure/Event/AdminAlertSubscriber.php` — NEW: listens to domain events, resolves admin-relevant ones, dispatches via AdminAlertPortInterface
- `src/Shared/Infrastructure/Health/HealthAlertService.php` — NEW: monitors health check results, fires alert when status changes (healthy → unhealthy)
- `tests/Unit/Notification/Domain/ValueObject/NotificationCategoryTest.php` — NEW

**Patterns to follow:**
- Existing `NotificationBridgeSubscriber` pattern for event-to-notification flow
- `CreateNotificationCommand` dispatched via Messenger
- `EventCategoryResolver` maps event class → category
- Query all users with `ROLE_ADMIN` or `ROLE_SUPER_ADMIN` to determine notification targets

**Test scenarios:**
- Job failure triggers admin notification with job name and error
- Library scan completion triggers notification with file counts
- Health check degradation triggers notification with component name
- New user registration triggers notification (if setting enabled)
- Admin notification correctly categorized as `AdminOperations`

**Verification:**
```bash
make phpunit tests/Unit/Notification/Domain/ValueObject/NotificationCategoryTest.php
make phpunit tests/Unit/Shared/Infrastructure/Event/AdminAlertSubscriberTest.php
```

**Dependencies:** Unit 1 (roles), Unit 2 (system settings for toggle).

---

### Unit 21: Admin In-App Notification Bell

**Goal:** Add a notification bell to the admin shell that shows unread count and recent admin alerts in real-time. Not just push — in-app visibility.

**Behavior:**
- Bell icon in AdminShell header (next to "Admin" title) with unread count badge
- Consumes `/api/notifications/sse` for real-time delivery
- Toast popups appear for admin alerts (job failed, scan completed, health changed)
- Click bell → dropdown shows last 10 notifications with category, title, time
- Click notification → navigates to relevant page (failed job → Job Monitor, scan → Libraries)
- Mark as read on click

**Files:**
- `ui/web/src/features/admin/components/AdminNotificationBell.tsx` — NEW
- `ui/web/src/features/admin/components/AdminNotificationDropdown.tsx` — NEW
- `ui/web/src/features/admin/components/AdminToast.tsx` — NEW: toast notification overlay
- `ui/web/src/features/admin/hooks/use-admin-notifications.ts` — NEW: SSE + query hook
- `ui/web/src/features/admin/api/notification-api.ts` — NEW: list, mark-read endpoints
- `ui/web/src/features/admin/components/AdminShell.tsx` — UPDATE: add bell to header

**Patterns to follow:**
- `useJobSse` pattern for SSE consumption
- shadcn `DropdownMenu` for dropdown
- Design language: subtle badge, `text-[11px]` timestamps, no decoration
- Toast: bottom-right, auto-dismiss 5s, `text-sm`, category-colored left border

**Test scenarios:**
- Bell renders with correct unread count
- SSE notification arrives → count increments, toast appears
- Click notification → navigates to correct page, marks as read
- Dropdown shows last 10 sorted by time
- Empty state: "No notifications" text

**Verification:**
```bash
cd ui/web && yarn test && yarn build
```

**Dependencies:** Unit 9 (AdminShell), Unit 20 (backend notification category).

---

### Unit 23: Notification Center in Main App Context Panel

**Goal:** Replace the "Now Playing" / "Player" text in the ContextPanel header with a notification bell. The bell shows unread count badge, toggles a popout notification list. Remove close/collapse buttons — panel auto-shows/hides based on content.

**Behavior:**
- Header: Bell icon (Bell from lucide) + unread count badge (red dot or number) replaces the "Player" / "Now Playing" text
- Click bell → popout list appears below (dropdown anchored to header)
- Popout shows last 20 notifications from all categories (security, background_jobs, media_changes, admin_operations)
- Each item: category-colored left border, title, relative timestamp, click navigates to relevant page
- Navigation mapping: `background_jobs.failed` → `/admin/monitor`, `library.scan_completed` → `/admin/libraries/{id}`, `user.registered` → `/admin` (if admin), `security.*` → `/settings`
- Mark-as-read on click. "Mark all read" button at popout bottom
- New notifications arrive via SSE stream at `/api/notifications/sse` — badge and list update in real-time
- Close/collapse buttons removed from context panel header. Panel auto-shows when there's content (player active, notification exists), auto-hides when empty

**No backend work needed.** All endpoints already exist:
- `GET /api/notifications` — list with category filter, cursor pagination
- `GET /api/notifications/unread-count` — badge count
- `PATCH /api/notifications/{id}/read` — mark read
- `PATCH /api/notifications/read-all` — mark all read
- `DELETE /api/notifications/{id}` — delete
- SSE at `/api/notifications/sse` — real-time stream

**Files:**
- `ui/web/src/features/layout/components/ContextPanel.tsx` — MODIFY: replace header text with NotificationBell, remove close/collapse buttons, add auto-show/hide logic
- `ui/web/src/features/layout/stores/context-panel-store.ts` — MODIFY: remove `setOpen`/`toggle` manual controls, add auto-visibility logic
- `ui/web/src/features/notification/` — NEW feature directory
  - `api/notification-api.ts` — typed API client wrapping existing endpoints
  - `stores/notification-store.ts` — Zustand store: notifications list, unread count, SSE state
  - `hooks/use-notifications.ts` — react-query hook for initial fetch + pagination
  - `hooks/use-notification-sse.ts` — SSE consumer that updates store in real-time
  - `components/NotificationBell.tsx` — bell icon + badge + click handler
  - `components/NotificationPopout.tsx` — dropdown list with mark-all-read
  - `components/NotificationItem.tsx` — single notification row with category icon, title, time

**Patterns to follow:**
- SSE consumption: reuse `useJobSse` pattern (fetch + ReadableStream parsing)
- Zustand store: same pattern as `auth-store`, `player-store`
- Popout: shadcn `DropdownMenu` or custom positioned div with click-outside
- Design language: `text-[11px]` timestamps, category-colored 2px left border, monospace for IDs
- Context panel auto-visibility: derived from player state + notification state, no manual toggle

**Test scenarios:**
- Bell renders with correct unread count from API
- Click bell → popout opens with notification list
- SSE notification arrives → badge increments, item appears at top of list
- Click notification → navigates to correct page, marks as read
- "Mark all read" → clears badge, marks all items
- No notifications → empty state: "No notifications" muted text
- Context panel auto-hides when player inactive and no notifications
- Context panel auto-shows when notification arrives or player starts
- Admin-specific notifications (AdminOperations category) visible to admin users

**Verification:**
```bash
cd ui/web && yarn test && yarn build
```

**Dependencies:** Unit 20 (admin notification category must exist for AdminOperations events to flow). No backend dependencies — all endpoints exist.

---

### Unit 22: Live Metrics SSE on Admin Pages

**Goal:** Extend the SSE stream to carry event types beyond jobs, so every admin page can react to real-time engine state changes.

**New SSE event types (backend):**
- `health.changed` — health component status changed (PostgreSQL/Redis/Swoole/Memory)
- `transcode.progress` — transcode session progress update
- `scan.progress` — library scan status change (started/completed/failed)

**Frontend:**
- `useAdminSse()` — multiplexed hook that consumes the SSE stream and returns typed events
- Pages subscribe to relevant event types via selector

**Files:**
- `src/Shared/Interface/Controller/SseController.php` — EXTEND: add new event type emission on health change, transcode progress, scan status
- `ui/web/src/features/admin/hooks/use-admin-sse.ts` — NEW: multiplexed SSE consumer with typed event selectors
- `ui/web/src/features/admin/components/LiveHealthBar.tsx` — NEW: compact health status bar using SSE
- `ui/web/src/features/admin/components/TranscodeProgress.tsx` — NEW: live progress bar component

**Patterns to follow:**
- Existing `SseController` polling pattern for job events
- `useJobSse` fetch + ReadableStream pattern

**Test scenarios:**
- SSE stream includes `health.changed` events when Redis disconnects
- SSE stream includes `transcode.progress` events during active transcode
- `useAdminSse` hook correctly parses all event types
- Components update reactively on new events

**Verification:**
```bash
make phpunit tests/Functional/Controller/SseControllerTest.php
cd ui/web && yarn test && yarn build
```

**Dependencies:** Unit 1 (admin auth for SSE).

---

## Parallelization Strategy

```
Phase A (sequential — foundation):
  Unit 1: Role system
  ├── Unit 2: System settings (depends on 1)
  ├── Unit 3: Library permissions (depends on 1)
  ├── Unit 5: TestAuthenticator fix (depends on 1)
  ├── Unit 20: Admin notification category (depends on 1, 2)
  ├── Unit 22: Live metrics SSE (depends on 1)
  └── Unit 9: Sidebar redesign (depends on 2)

Phase B (parallel — backend endpoints):
  Unit 4: Admin user CRUD (depends on 1,2,3)
  Unit 6: Activity analytics (depends on 1)
  Unit 7: Recommendation insights (depends on 1)
  Unit 8: Lyrics admin (depends on 1)

Phase C (parallel — frontend pages):
  Unit 10: User management page (depends on 4, 9)
  Unit 11: System settings page (depends on 2, 9)
  Unit 12: Metadata page (depends on 9, 22)
  Unit 13: Lyrics page (depends on 8, 9)
  Unit 14: Transcode page (depends on 9, 22)
  Unit 15: Radio page (depends on 9)
  Unit 16: Enhanced diagnostics (depends on 9)
  Unit 17: Activity page (depends on 6, 9)
  Unit 18: Recommendations page (depends on 7, 9)
  Unit 23: Notification center in main app (depends on 20)
  Unit 21: Admin notification bell (depends on 9, 20, 23)

Phase D (sequential — polish):
  Unit 19: Dashboard enhancement (depends on all)
```

## Verification Strategy

### Per-unit verification
Each unit lists specific PHPUnit test commands and frontend build checks.

### Integration verification
After all units complete:
1. Run full test suite: `make phpunit`
2. Run PHPStan: `make phpstan`
3. Run frontend build: `cd ui/web && yarn build`
4. Run frontend tests: `cd ui/web && yarn test`
5. Verify OpenAPI spec regenerates: `make openapi`
6. Run DDD lint on changed contexts: `.claude/skills/dddlint`
7. Verify all new admin routes appear in `bin/console debug:router | grep admin`

### Manual verification
1. Login as SUPER_ADMIN → verify all sidebar groups visible
2. Login as ADMIN → verify Users group hidden by default
3. Toggle `admin.can_view_users` → verify ADMIN can now see Users (read-only)
4. Create user from admin → verify appears in list
5. Grant library access → verify user can browse that library
6. Run metadata search from admin → verify results display
7. Trigger bulk lyrics fetch → verify job appears in Job Monitor
8. View activity analytics → verify charts render
9. **Dashboard live metrics** — verify health dots update in real-time, operations feed shows running jobs
10. **Notification bell** — trigger a job failure, verify toast appears and bell count increments
11. **Transcode progress** — start transcode, verify progress bar animates on admin page
12. **Quick actions** — click "Retry Failed Jobs" from dashboard, verify action dispatches and feedback shown
13. **Alert on health change** — stop Redis, verify admin gets notification within 30s
