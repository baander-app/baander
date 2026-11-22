---
title: Classify video playback and admin log routes
date: 2026-11-22
type: fix
status: completed
---

# Classify video playback and admin log routes

## Goal Capsule

Restore the OAuth route inventory and make video playback information available to delegated clients with `library` scope and a grant to the source library. Keep log access first-party only. [Roadmap](../../ROADMAP.md#password-rehash-and-emergency-oauth-rotation).

## Product Contract

- R1. Delegated tokens need `library` scope to reach video playback information and timeline previews. Each request also needs access to the source library. A delegated token issued to an administrator uses the same library grant rules.
- R2. The four admin log routes require a first-party token and `ROLE_SUPER_ADMIN`.
- R3. Every compiled route is classified once. Add only the six entries the current inventory identifies.

## Planning Contract

The inventory reports four failing assertions against the same six missing routes. The two video controller methods pass the current library scope to `VideoPlaybackInformationService`. The service resolves scoped source metadata before describing or reading a preview. All four log actions already carry `ROLE_SUPER_ADMIN`. Extend `ScopeRouteMap` using the existing categories and keep the controller checks. [OAuth scope contract](../../docs-book/part-2-developer-guide/contexts/auth-tokens.md#scope-enforcement).

## Implementation Units

### U1. Classify the routes and verify access

Map `video_playback_information` and `video_playback_preview` to `library`. Register `admin_logs_sources`, `admin_logs_entries`, `admin_logs_detail` and `admin_logs_query` as first-party. Extend the production-firewall scope test with a granted video, an ungranted video and delegated attempts to read logs.

### U2. Record the verification checkpoint

Record the current inventory and firewall results in [ROADMAP.md](../../ROADMAP.md). Keep the earlier failing gate as historical evidence.

## Verification Contract

- AE1. The complete route inventory passes and continues to detect unclassified routes, duplicate entries and scoped admin routes.
- AE2. A delegated token with `library` and a grant reads playback information and reaches the timeline preview controller. A video with no generated preview returns the controller's 404.
- AE3. A delegated token without `library` receives `insufficient_scope` on both routes. A delegated token of an administrator with `library` receives 404 for a fixture video in a library outside its grants.
- AE4. All four log routes reject delegated tokens, including a token with every scope issued to a super administrator. Existing functional checks cover anonymous and ordinary administrator denial and first-party super administrator access.
- Run the Auth unit suites, video-information unit tests, production OAuth scope integration tests, admin log functional tests, PHP syntax and whitespace checks.

## Definition of Done

The inventory and access tests pass, and the roadmap records the check counts and the permissions each route uses.

The [roadmap checkpoint](../../ROADMAP.md#oauth-route-scope-inventory) records the unit and deployed-stack results from the CI image.
