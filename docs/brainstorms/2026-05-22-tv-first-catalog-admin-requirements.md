---
date: 2026-05-22
topic: tv-first-catalog-admin
---

# TV-First Catalog and Admin Views for React Native

## Summary

TV-first React Native implementation of catalog browsing (home with featured/continue listening/recently added/discovery sections, albums, artists, genres, search, detail pages) and full admin panel with D-pad-optimized layouts, using a shared state layer for future mobile/desktop reuse.

---

## Problem Frame

The web app (`ui/web`) has full catalog browsing and admin functionality, but the React Native app (`ui/rn`) has only basic mobile views with no TV interface. TV users need a native app experience optimized for 10-foot viewing distance and D-pad input, not an adapted mobile UI. Building TV-first with a shared state layer establishes patterns that mobile and desktop can follow, avoiding the technical debt of retrofitting TV concerns into a mobile-first architecture.

---

## Actors

- A1. **TV user**: Uses Baander on Apple TV or Android TV with a remote control (D-pad input), viewing from ~10 feet away
- A2. **Admin user**: Manages server configuration, users, and monitoring through the TV interface
- A3. **Mobile/desktop developer (future)**: Will reuse the shared state layer when implementing mobile and desktop UIs

---

## Key Flows

- F1. **Browse and play music from TV home**
  - **Trigger:** TV user opens app and navigates to home screen
  - **Actors:** A1
  - **Steps:**
    1. TV home screen loads with four sections: featured/promoted, continue listening, recently added, discovery/recommendations
    2. User navigates using D-pad (up/down between sections, left/right within sections)
    3. User selects an album or track
    4. Detail screen opens with track listing
    5. User selects a track to play
    6. Audio playback begins with now-playing overlay available
  - **Outcome:** Music is playing, user can continue browsing or control playback
  - **Covered by:** R1, R2, R3, R4, R6

- F2. **Admin views server status on TV**
  - **Trigger:** Admin user navigates to admin section from TV home
  - **Actors:** A2
  - **Steps:**
    1. Admin selects admin option from navigation
    2. Admin dashboard loads with server stats
    3. User navigates to specific admin page (jobs, rate limiters, etc.)
    4. Page renders TV-optimized layout (simplified vs web)
    5. User can view status and make basic configuration changes
  - **Outcome:** Admin can monitor and manage server from TV
  - **Covered by:** R7, R8, R9

---

## Requirements

**TV Foundation**
- R1. TV app routes to dedicated TV shell when running on Apple TV or Android TV (Platform.isTV)
- R2. TV shell provides full-screen layout optimized for 10-foot viewing distance (no sidebar, no context panel, no mini-player bar)
- R3. TV components support D-pad navigation with explicit focus management (up/down/left/right/enter)

**Catalog — TV UI**
- R4. TV home screen renders four sections: featured/promoted content, continue listening (recently played), recently added albums, and discovery/recommendations
- R5. TV content rows support horizontal scrolling with D-pad left/right navigation
- R6. TV detail screens (album, artist) display full-screen with track listing and playback controls
- R7. TV search screen provides on-screen keyboard input and result display
- R8. TV genres page allows genre selection and content browsing

**Admin — TV UI**
- R9. TV admin dashboard renders server stats in TV-optimized layout (large text, simplified visuals)
- R10. TV admin pages include all web admin features: dashboard, job monitor, rate limiters, server diagnostics, configuration, users, activity, genres, metadata, recommendations, transcode, lyrics admin, album duplicates
- R11. TV admin pages simplify complex web UI patterns (tables, multi-column layouts) for D-pad navigation while retaining full functionality
- R12. Admin route guards restrict access to users with admin role

**Shared State Layer**
- R13. Zustand stores (player, auth, catalog selection, view mode) are platform-agnostic and reusable
- R14. Data fetching hooks (useAlbums, useArtists, useTracks, useGenres) are platform-agnostic
- R15. API client (ui/shared/) provides DPoP auth, token refresh, and type-safe endpoints

**Design System**
- R16. TV uses shared color tokens from web (dark theme palette)
- R17. TV typography and spacing tokens override web defaults for 10-foot viewing distance (24px+ body text, larger gaps)
- R18. TV focus indicators provide clear visual feedback for the currently focused element

**Navigation**
- R19. TV navigation uses D-pad model (up/down between sections, left/right within sections, enter to select, back to dismiss)
- R20. TV now-playing overlay appears on top of browse content when music is playing
- R21. TV login screen uses on-screen keyboard for server URL and credentials

---

## Acceptance Examples

- AE1. **Covers R1, R2, R4, R19.** Given TV app launches on Apple TV, when home screen loads, then user sees four sections (featured, continue listening, recently added, discovery) and can navigate using up/down between sections and left/right within sections.
- AE2. **Covers R5, R18.** Given user is on TV home screen, when user navigates to a content row, then left/right moves focus between cards and focused card shows clear visual indicator.
- AE3. **Covers R6, R20.** Given user selects an album on TV home, when album detail loads and user plays a track, then now-playing overlay appears and can be dismissed with back button.
- AE4. **Covers R9, R11.** Given admin user opens admin dashboard on TV, when page loads, then server stats display in simplified layout optimized for D-pad navigation (no complex tables).
- AE5. **Covers R12.** Given non-admin user attempts to access admin section on TV, when navigation occurs, then user is redirected or shown access denied message.

---

## Success Criteria

- TV user can browse music catalog, search, and play tracks using only D-pad input
- TV UI is clearly designed for 10-foot viewing (large text, clear focus, high contrast)
- Admin can perform common management tasks from TV without mouse/keyboard
- Shared state layer (stores, hooks, API client) has no TV-specific code and can be reused by mobile/desktop
- Web app continues working without changes (no regressions from shared layer extraction)

---

## Scope Boundaries

### In Scope
- TV-first catalog views: home (featured, continue listening, recently added, discovery), albums, artists, genres, search, album/artist detail pages
- TV-first admin views: all web admin pages with D-pad-optimized layouts (dashboard, job monitor, rate limiters, diagnostics, configuration, users, activity, genres, metadata, recommendations, transcode, lyrics admin, album duplicates)
- Shared Zustand stores, data fetching hooks, and API client (ui/shared/)
- TV design tokens (typography, spacing overrides)
- D-pad focus management and navigation system
- TV login screen with on-screen keyboard

### Out of Scope
- Mobile and desktop UI implementation (deferred to follow-on work)
- Audio module implementation (covered in existing rn-app.md plan)
- Auth flow implementation (covered in existing rn-app.md plan)
- Server discovery and deep linking (covered in existing rn-app.md plan)
- Other media types: movies, TV shows, podcasts, concerts, ebooks
- Equalizer DSP (not feasible in RN)
- CI/CD pipeline for app store builds
- Automated E2E tests (manual verification per platform)

---

## Key Decisions

- **TV-first over mobile-first:** TV is the stated priority despite mobile allowing faster iteration. Dedicated TV UI from the start avoids retrofitting D-pad patterns onto touch-optimized components.
- **Custom TV UI over existing patterns:** Building a revolutionary user-friendly interface rather than copying Netflix/Apple Music. The exact navigation metaphor and layout will be designed during implementation.
- **Shared state layer, separate UI components:** Stores, hooks, and API client are shared across all platforms. UI components are platform-specific due to fundamentally different interaction models (D-pad vs touch vs mouse).
- **Admin panel simplification for TV:** Complex web patterns (tables, multi-column layouts) will be simplified for D-pad navigation, but full admin functionality is available on TV (all web admin pages: dashboard, jobs, rate limiters, diagnostics, config, users, activity, genres, metadata, recommendations, transcode, lyrics, duplicates).

---

## Dependencies / Assumptions

- **Existing rn-app.md plan:** Audio module (Unit 5), auth flow (Unit 3), and server discovery (Unit 11) are implemented separately per the existing plan
- **react-native-tvos:** TV-specific native module APIs (TVFocusGuideView, TVEventHandler) are available for focus management
- **Backend API:** Existing backend endpoints for catalog, admin, and auth remain unchanged
- **Web app stability:** Web app continues working after shared layer extraction to ui/shared/

---

## Outstanding Questions

### Deferred to Planning

- [Affects R3][Technical] Exact focus management approach — should we use react-native-tvos's TVFocusGuideView, build a custom focus system, or use a third-party library?
- [Affects R4][Needs research] What TV navigation metaphor works best for catalog browsing? Horizontal rows, vertical lists, grid, hybrid?
