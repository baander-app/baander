---
date: "2026-06-04"
topic: "android-read-only-client"
title: "Android Read-Only Client Requirements"
---

## Summary

A new simplified Android client app for casual Baander users. Users authenticate through a global discovery service and connect to their self-hosted server. The app focuses on content consumption — browse library, search, play music, create playlists, favorite tracks, queue management — with no admin or library management features.

## Problem Frame

Baander is a self-hosted media library server with full-featured clients across platforms. However, the existing mobile app inherits the full feature surface, including server management and administrative tools that casual users don't need or want. Casual users connect to their own self-hosted instance (often managed by someone else) and simply want to consume content. The current authentication flow also requires manual server URL entry, which is friction for non-technical users.

A dedicated read-only Android client with simplified authentication lowers the barrier to entry for casual users while reducing UI complexity.

## Key Decisions

**Separate app, not a mode.** This is a new simplified Android client app, not a role-based mode within the existing React Native app. The existing app remains for power users; the new app targets casual users.

**Discovery service required.** Authentication flows through a new global discovery service backend that users will interact with first, before connecting to their self-hosted server. This service does not currently exist and must be built as part of this work.

**Standard feature scope.** The app includes core consumption features (browse, search, play, mini-player) plus user-specific personalization (create own playlists, love/favorite tracks, queue management, basic settings). Admin features, library management, and server configuration are explicitly out of scope.

**Three authentication methods.** The discovery service supports three paths: QR code scan, email + server URL, or server code + ID. This accommodates different user preferences and technical comfort levels.

## Actors

- **A1. Casual user** — Non-technical user connecting to a self-hosted Baander server they (or someone else) manage. Wants to browse and play music, not manage servers.

- **A2. Discovery service** — Backend service that maps user credentials to their self-hosted server instance and handles initial authentication.

- **A3. Self-hosted Baander server** — The user's media library server that streams content and stores user data (playlists, favorites, queue).

## Requirements

### Authentication and Onboarding

- R1. The app supports three authentication methods through the discovery service: scan QR code, enter email + server URL, or enter server code + ID.

- R2. After authentication, the app connects directly to the user's self-hosted server for all media operations.

- R3. Credentials and server connection details persist securely on device for subsequent launches.

### Content Consumption

- R4. Users browse their media library by artist, album, genre, and other catalog views.

- R5. Users perform full-text search across their library.

- R6. Users play any track in their library with standard transport controls (play, pause, next, previous, seek, shuffle, repeat).

- R7. A mini-player persists across the app showing current track and basic controls.

### Personalization

- R8. Users create, edit, and delete their own playlists.

- R9. Users love/favorite tracks and view a collection of favorited items.

- R10. Users manage their playback queue (add, remove, reorder).

- R11. Users access basic settings (server connection details, account info, logout).

### Exclusions

- R12. The app does NOT include admin features, library management, server configuration, or any capability beyond consumption and user-specific personalization.

## Key Flows

### F1. First-Time Authentication via QR Code

- **Trigger:** User launches the app for the first time and selects QR code option.
- **Actors:** A1 (casual user), A2 (discovery service), A3 (self-hosted server).
- **Steps:**
  1. App displays QR code scanner.
  2. User scans QR code from their Baander server (or shared via another method).
  3. App sends QR payload to discovery service.
  4. Discovery service returns server URL and authentication token.
  5. App stores connection details and authenticates with the user's server.
  6. App navigates to main content view.
- **Outcome:** User is authenticated and connected to their server.

### F2. First-Time Authentication via Email + Server

- **Trigger:** User launches the app for the first time and selects email + server option.
- **Actors:** A1 (casual user), A2 (discovery service), A3 (self-hosted server).
- **Steps:**
  1. App prompts for email address and server URL.
  2. User enters credentials.
  3. App sends credentials to discovery service.
  4. Discovery service validates and returns authentication token.
  5. App stores connection details and authenticates with the user's server.
  6. App navigates to main content view.
- **Outcome:** User is authenticated and connected to their server.

### F3. First-Time Authentication via Server Code + ID

- **Trigger:** User launches the app for the first time and selects server code option.
- **Actors:** A1 (casual user), A2 (discovery service), A3 (self-hosted server).
- **Steps:**
  1. App prompts for server code and user ID.
  2. User enters credentials.
  3. App sends credentials to discovery service.
  4. Discovery service maps code+ID to server URL and returns authentication token.
  5. App stores connection details and authenticates with the user's server.
  6. App navigates to main content view.
- **Outcome:** User is authenticated and connected to their server.

### F4. Subsequent App Launch

- **Trigger:** User launches the app after successful first-time authentication.
- **Actors:** A1 (casual user), A3 (self-hosted server).
- **Steps:**
  1. App loads stored server URL and credentials.
  2. App authenticates with stored credentials.
  3. If credentials are invalid, app prompts for re-authentication via one of the three methods.
  4. App navigates to main content view.
- **Outcome:** User is authenticated without re-entering credentials.

## Scope Boundaries

### Deferred for later

- Multi-server support (connecting to more than one Baander instance)
- Offline mode or caching
- Social features (sharing, collaborative playlists)
- Advanced audio features (equalizer, lyrics display)

### Outside this product's identity

- Server management and administration
- Library scanning and metadata management
- User account creation on the self-hosted server (handled separately)
- Premium module licensing (separate auth infrastructure)

## Dependencies / Assumptions

- The global discovery service backend must be implemented as part of this work.
- The user's self-hosted Baander server is accessible from the Android device (network connectivity, firewall configuration).
- The existing Baander API endpoints for catalog, playback, and user personalization remain stable.
- Minimum Android API level: 33+ (Android 13 and newer).
- Responsive UI design that adapts to phone and tablet screen sizes.

## Outstanding Questions

### Deferred to planning

- Exact UI component library selection (NativeWind, React Native Paper, or custom)
- State management approach (Zustand is used in the existing RN app — reuse or pick differently?)
- Navigation structure specifics (tab-based, stack-based, or hybrid)
- Discovery service API contracts (defined during implementation)

## Sources / Research

- Existing React Native app structure: `ui/rn/`
- Authentication store pattern: `ui/rn/src/features/auth/stores/auth-store.ts`
- Catalog API hooks: `ui/rn/src/features/catalog/hooks/`
- Player store: `ui/rn/src/features/player/stores/player-store.ts`
