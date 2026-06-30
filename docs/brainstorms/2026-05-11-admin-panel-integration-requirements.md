# Admin Panel Integration — Requirements

**Date:** 2026-05-11
**Status:** Approved
**Mode:** CE Brainstorm (feature addition)

---

## Problem

The backend has 14 bounded contexts with extensive API surface, but the admin panel only surfaces infrastructure monitoring (jobs, diagnostics, rate limiters, config checks). The operational "last mile" — managing users, content, transcode jobs, radio stations, metadata, analytics — has no UI. An admin must use CLI commands or direct API calls to perform these operations.

## Goals

1. **Wire every backend capability into the admin panel** with proper UI pages.
2. **Implement a three-tier role system** (SUPER_ADMIN, ADMIN, USER) with library-level access grants.
3. **Add a system settings concept** where SUPER_ADMIN can toggle admin capabilities.
4. **Redesign the admin sidebar** into grouped, collapsible sections to accommodate the expanded feature set.
5. **Maintain the existing design language** — dark, minimal, content-first, flat surfaces, monospace metadata, no decoration.

## Non-goals

- Granular per-context permission system (custom roles with individual capability assignment).
- Admin audit logging (could follow later).
- Admin action undo/rollback beyond what individual contexts already support.
- Email notification templates editor.
- Multi-tenant isolation — library permissions control access, not data segregation.

## Approach Options

### Option A: Full panel with all pages at once
Build every page, every backend endpoint, complete sidebar redesign in one push.
- **Pros:** Complete admin experience, consistent patterns established everywhere.
- **Cons:** Large PR, harder to review incrementally, longer before anything ships.

### Option B: Layered by domain — ship per group
Implement group by group: Users first, then Content, then Operations, then Analytics. Each group is a shippable increment.
- **Pros:** Incremental delivery, easier to review, can adjust patterns as we go.
- **Cons:** Sidebar redesign must happen upfront or look incomplete.

### Option C: Backend-first, then frontend sweep
Create all missing backend endpoints, then build all frontend pages.
- **Pros:** Backend is testable independently, frontend can be built against stable API.
- **Cons:** Longer before any visible progress.

### Recommended Direction

**Option B — ship per group**, with the sidebar redesign and role system as the foundation layer.

## Phasing

### Phase 1: Foundation
- Role system (SUPER_ADMIN/ADMIN/USER hierarchy)
- Updated AdminVoter with SUPER_ADMIN vs ADMIN distinction
- System settings backend (key-value store)
- Sidebar redesign with grouped collapsible navigation
- Library permission model (join table + grant/revoke)
- Schema migrations: `ROLE_SUPER_ADMIN`, `user_library_access` table, `system_settings` table

### Phase 2: Users Group
- User management CRUD page (list, create, edit, delete, role assignment, password reset)
- System settings page with grouped toggles and descriptions
- Library permission assignment per user
- AdminRoute guard updated for role-based sidebar visibility

### Phase 3: Content Group
- Metadata operations page (sync triggers, external API search, MusicBrainz browse)
- Lyrics admin page (bulk fetch trigger, coverage stats)
- Library detail page enhancement (permission management UI)

### Phase 4: Operations Group
- Transcode management page (job list, session management, cleanup trigger)
- Radio management page (stations, sources, country subscriptions, sync)
- Enhanced diagnostics (surface coroutines, workers, spans, prometheus link)

### Phase 5: Analytics Group
- Activity analytics dashboard (play counts, top tracks, engagement, history)
- Recommendation insights page (coverage, source quality, per-user insights)

### Phase 6: Polish
- Dashboard enhancements (summary cards pulling data from all groups)
- Notification management (webhooks, push subscriptions)
- Bulk catalog operations (cover extraction triggers)

---

## Role & Permission Model

### Roles (hierarchical)

```
ROLE_SUPER_ADMIN → ROLE_ADMIN → ROLE_USER
```

| Role | Admin Access | User Management | System Settings | Content Ops | Analytics |
|------|-------------|----------------|----------------|-------------|-----------|
| SUPER_ADMIN | Full | Full CRUD + role assignment | Read/Write | Full | Full |
| ADMIN | Full (minus user mgmt unless granted) | None (or read-only if SUPER_ADMIN grants via setting) | Read-only | Full | Full |
| USER | None | None | None | None | None |

Only SUPER_ADMIN can promote users to ADMIN.

### Library Permissions
- Simple grant/revoke: `user_library_access(user_id, library_id)` join table.
- SUPER_ADMIN implicitly has access to all libraries (no row needed).
- Managed per-user in the user management page, or per-library in the library detail page.
- No access levels — granted means full access (browse, listen, scan trigger).

### System Settings (SUPER_ADMIN toggles)
- `admin.can_view_users` — Whether ADMIN role can see user management page (default: false)
- Stored as key-value in `system_settings` table.
- Future settings added without schema changes.

---

## Admin Sidebar Structure

```
▼ Overview
  Dashboard

▼ Users            (SUPER_ADMIN only, or ADMIN if setting enabled)
  User Management
  System Settings   (SUPER_ADMIN only)

▼ Content
  Libraries
  Metadata
  Lyrics

▼ Operations
  Jobs
  Transcode
  Radio

▼ Monitoring
  Diagnostics
  Rate Limiters
  Configuration

▼ Analytics
  Activity
  Recommendations

──────────
← Back to app
```

---

## Backend Work Needed

### New Endpoints

1. **Auth context — `AdminUserController`:**
   - `GET /api/admin/users` — List users (paginated, filterable by role/disabled)
   - `POST /api/admin/users` — Create user
   - `PATCH /api/admin/users/{id}` — Update user (name, email)
   - `DELETE /api/admin/users/{id}` — Delete user
   - `POST /api/admin/users/{id}/roles` — Assign roles
   - `POST /api/admin/users/{id}/reset-password` — Reset password
   - `POST /api/admin/users/{id}/disable` — Disable user
   - `POST /api/admin/users/{id}/enable` — Enable user
   - `GET /api/admin/users/{id}/library-access` — List library permissions
   - `POST /api/admin/users/{id}/library-access` — Grant library access
   - `DELETE /api/admin/users/{id}/library-access/{libraryId}` — Revoke library access

2. **Auth context — `AdminVoter` update:**
   - Add `USER_MANAGEMENT` attribute requiring `ROLE_SUPER_ADMIN` (or `ROLE_ADMIN` if setting enabled).
   - Add `SYSTEM_SETTINGS` attribute requiring `ROLE_SUPER_ADMIN`.

3. **Shared — `SystemSettingsController`:**
   - `GET /api/admin/settings` — List all settings
   - `PATCH /api/admin/settings` — Update settings (SUPER_ADMIN only)

4. **Activity context — `AdminActivityController`:**
   - `GET /api/admin/activity/summary` — Overall stats (total plays, active users, etc.)
   - `GET /api/admin/activity/top-tracks` — Most played songs (time-range filterable)
   - `GET /api/admin/activity/engagement` — Per-user engagement metrics

5. **Recommendation context — `AdminRecommendationController`:**
   - `GET /api/admin/recommendations/coverage` — % of catalog with recommendations
   - `GET /api/admin/recommendations/sources` — Per-source quality/coverage stats

6. **Lyrics context — `AdminLyricsController`:**
   - `POST /api/admin/lyrics/bulk-fetch` — Trigger bulk lyrics fetch job
   - `GET /api/admin/lyrics/coverage` — % of songs with lyrics, per-source breakdown

### Existing Endpoints Needing Frontend Pages

- `/api/transcode/jobs/*`, `/api/transcode/sessions/*` — Transcode management
- `/api/metadata/*` (search, sync, browse) — Metadata operations
- `/api/radio/*` (sources, stations, subscriptions, country sync) — Radio management
- `/api/webhooks/*`, `/api/push/*` — Notification management
- `/api/debug/coroutines`, `/api/debug/workers`, `/api/debug/spans` — Enhanced diagnostics
- `/api/albums/covers/extract` — Cover extraction trigger

### Schema Changes

1. **Add `ROLE_SUPER_ADMIN`** to allowed roles in `CreateUserHandler::ALLOWED_ROLES`
2. **Create `user_library_access` table:**
   ```sql
   CREATE TABLE user_library_access (
     user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
     library_id UUID NOT NULL REFERENCES libraries(id) ON DELETE CASCADE,
     granted_at TIMESTAMP NOT NULL DEFAULT NOW(),
     PRIMARY KEY (user_id, library_id)
   );
   ```
3. **Create `system_settings` table:**
   ```sql
   CREATE TABLE system_settings (
     key TEXT PRIMARY KEY,
     value JSONB NOT NULL,
     updated_at TIMESTAMP NOT NULL DEFAULT NOW()
   );
   ```
4. **Update `security.yaml`:**
   - Add `ROLE_SUPER_ADMIN: ROLE_ADMIN` to role_hierarchy
   - Add access_control for `/api/admin/*` routes

---

## Frontend Architecture

All new pages follow the established pattern in `features/admin/`:

```
features/admin/
├── api/
│   ├── user-admin-api.ts          (NEW)
│   ├── system-settings-api.ts     (NEW)
│   ├── transcode-admin-api.ts     (NEW)
│   ├── metadata-admin-api.ts      (NEW)
│   ├── lyrics-admin-api.ts        (NEW)
│   ├── radio-admin-api.ts         (NEW)
│   ├── activity-admin-api.ts      (NEW)
│   ├── recommendation-admin-api.ts (NEW)
│   ├── server-stats-api.ts        (exists)
│   ├── job-monitor-api.ts         (exists)
│   ├── config-check-api.ts        (exists)
│   └── rate-limiter-api.ts        (exists)
├── hooks/
│   ├── use-user-admin.ts          (NEW)
│   ├── use-system-settings.ts     (NEW)
│   └── ... (one per api module)
├── components/
│   ├── AdminSidebar.tsx           (REWRITE — grouped collapsible sections)
│   ├── AdminShell.tsx             (minor update)
│   ├── AdminRoute.tsx             (update — SUPER_ADMIN/ADMIN check + conditional sidebar items)
│   └── ... (per-page components)
└── pages/
    ├── UserManagementPage.tsx     (NEW — Phase 2)
    ├── SystemSettingsPage.tsx     (NEW — Phase 2)
    ├── MetadataPage.tsx           (NEW — Phase 3)
    ├── LyricsAdminPage.tsx        (NEW — Phase 3)
    ├── TranscodePage.tsx          (NEW — Phase 4)
    ├── RadioAdminPage.tsx         (NEW — Phase 4)
    ├── ActivityPage.tsx           (NEW — Phase 5)
    ├── RecommendationsPage.tsx    (NEW — Phase 5)
    ├── AdminDashboardPage.tsx     (ENHANCE — Phase 6)
    ├── ServerDiagnosticsPage.tsx  (ENHANCE — Phase 4)
    ├── NotificationPage.tsx       (NEW — Phase 6)
    ├── JobMonitorPage.tsx         (exists)
    ├── RateLimitersPage.tsx       (exists)
    └── ConfigurationPage.tsx      (exists)
```

---

## Design Adherence

All pages follow DESIGN.md:
- Background `#000000`, card `#0a0a0b`, borders `#1a1a1f`
- Page padding `px-6`, section gap `gap-8`, item gap `gap-4`
- Font: Inter, body `text-sm`, labels `text-[11px]` uppercase tracking-wider
- Monospace: JetBrains Mono for metadata values (IDs, timestamps, memory stats)
- No gradients, no shadows (except overlays), no bounce animations
- Skeleton loading states matching final layout
- Error states in `text-destructive` with retry buttons
- Empty states: one line of muted text, no illustrations
- All buttons use shadcn `Button` component
- All lists >50 items virtualized with `@tanstack/react-virtual`

---

## Success Criteria

1. Admin panel exposes all backend capabilities through grouped, navigable pages.
2. Three-tier role system works: SUPER_ADMIN has full control, ADMIN has operational access, USER has no admin access.
3. Library permissions restrict user access to specific libraries.
4. System settings allow SUPER_ADMIN to toggle admin capabilities.
5. Every new admin endpoint is covered by tests (Unit + Functional).
6. All pages follow the design language in DESIGN.md.
7. Sidebar navigation scales to ~14 items without feeling cluttered.
