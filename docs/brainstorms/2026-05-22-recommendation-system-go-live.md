---
date: 2026-05-22
topic: recommendation-system-go-live
---

# Recommendation System Go-Live

## Summary

Complete the recommendation system with generation infrastructure, user discovery surface, feedback collection, and explanation UI. Enables users to discover music through algorithmic suggestions while providing transparency and control over their recommendation experience.

---

## Problem Frame

The recommendation system has backend infrastructure (4 calculation strategies, aggregate, queries) and admin monitoring, but lacks any way to generate recommendations or expose them to users. Users currently discover music only through browsing, search, and existing playlists — no algorithmic discovery exists.

---

## Actors

- A1. **Admin**: Triggers manual recommendation generation via CLI or admin UI, monitors system health
- A2. **End user**: Views recommendations, provides feedback, explores explanation details
- A3. **Scheduled job system**: Runs automatic incremental (nightly) and full (weekly) recommendation generation

---

## Key Flows

- F1. **Manual generation (admin)**
  - **Trigger:** Admin runs CLI command or clicks trigger button in admin UI
  - **Actors:** A1
  - **Steps:** Admin selects mode (full/incremental) and triggers; system computes recommendations using 4 strategies; results persisted to database; admin notified of completion
  - **Outcome:** Fresh recommendations stored and available for queries
  - **Covered by:** R1, R2, R3

- F2. **Automatic generation (scheduled)**
  - **Trigger:** Cron schedule (nightly for incremental, weekly for full)
  - **Actors:** A3
  - **Steps:** Job invokes generation with appropriate mode; system computes delta or full refresh; results persisted; metrics updated
  - **Outcome:** Recommendations stay fresh without manual intervention
  - **Covered by:** R4

- F3. **User discovery with feedback**
  - **Trigger:** User navigates to `/recommendations` page
  - **Actors:** A2
  - **Steps:** System fetches ranked recommendations for user; user views list with one-line explanations; user clicks to expand detailed breakdown or plays track; user provides explicit feedback (thumbs/up/down) or implicit feedback (play/click) is recorded
  - **Outcome:** User discovers new music; feedback signals captured for future scoring improvements
  - **Covered by:** R5, R6, R7, R8, R9, R10

---

## Requirements

**Generation infrastructure**

- R1. CLI command accepts mode flag (`--full` or `--incremental`) and triggers recommendation computation across all 4 strategies (collaborative filtering, content similarity, genre similarity, database relations)
- R2. Admin UI exposes a trigger button on the Recommendations page with mode selection; shows generation status (idle, running, completed with timestamp)
- R3. Full generation clears existing recommendations and recomputes from scratch; incremental generation only processes new/changed content since last run

**Scheduled execution**

- R4. Cron jobs configured for nightly incremental and weekly full refresh; jobs use same generation backend as manual triggers

**User-facing display**

- R5. `/recommendations` page displays ranked list of recommended tracks/albums for the authenticated user
- R6. Each recommendation shows a one-line explanation by default (e.g., "Because you liked X", "Similar to Y")
- R7. Each recommendation item is expandable to show detailed multi-factor breakdown with per-strategy scores

**Feedback collection**

- R8. Each recommendation item has thumbs up/down and "not interested" explicit feedback controls
- R9. Implicit feedback is captured when users play or click on recommended items
- R10. Feedback signals are persisted and available to influence future recommendation scoring (algorithm integration may be deferred to planning)

**Multi-user support**

- R11. System supports any number of users from 1 to many; recommendations computed per-user where applicable (collaborative filtering) and globally where appropriate (content similarity)

---

## Acceptance Examples

- AE1. **Covers R1, R3.** Given a library with 1000 tracks and existing recommendations, when admin runs `bin/console recommendations:generate --incremental`, only new or modified tracks are processed; existing recommendations for unchanged tracks remain; execution completes faster than full mode.
- AE2. **Covers R5, R6, R7.** Given a user with listening history, when they visit `/recommendations`, they see a ranked list of tracks; each item shows a one-line reason; clicking an item expands to show "Collaborative: 0.4, Content: 0.3, Genre: 0.2, Database: 0.1".
- AE3. **Covers R8, R9.** Given a recommendation item, when a user clicks thumbs down, the feedback is persisted; when a user clicks to play the track, the play is recorded as implicit positive feedback.

---

## Success Criteria

- Technical completion: CLI command executes successfully, admin trigger works, scheduled jobs run without errors, `/recommendations` page loads and displays data
- Admin monitoring page shows healthy coverage and freshness metrics after generation
- Feedback controls are responsive and persist data correctly

---

## Scope Boundaries

### Deferred for later

- Algorithm tuning based on feedback signals (persist signals first, integrate into scoring later)
- A/B testing different recommendation strategies or ranking algorithms
- Personalization weight adjustment (users tuning their own recommendation mix)
- Recommendations surfaces outside the dedicated page (inline in Library, Now Playing, etc.)
- Advanced explanation features (visual graphs, timeline views)

### Outside this product's identity

- Social recommendations ("friends also liked") — this is a personal/family server, not a social network
- Third-party recommendation service integrations (Spotify, Last.fm, etc.)
- Recommendation analytics dashboard for admins beyond existing coverage/quality/freshness metrics

---

## Key Decisions

- **Hybrid schedule:** Nightly incremental + weekly full refresh balances freshness with computational cost
- **Dedicated page only:** Recommendations live at `/recommendations` rather than scattered across existing pages — simpler v1, can expand later
- **Configurable explanations:** Default simplicity with expandable detail serves both casual and power users
- **Both feedback types:** Explicit and implicit capture different signal types; explicit gives control, implicit captures actual behavior

---

## Dependencies / Assumptions

- Existing `Recommendation` aggregate, calculators, and admin monitoring infrastructure are functional
- Messenger/Swoole infrastructure is available for async job processing
- `Activity` bounded context can provide listening history for collaborative filtering calculations
- Authenticated user context is available for per-user recommendation queries

---

## Outstanding Questions

### Deferred to Planning

- [Needs research] How should incremental generation detect "new or modified" content? Is there a last-modified timestamp on tracks/albums, or does it scan for missing recommendations?
- [Needs research] How should feedback signals be integrated into the 4 calculation strategies? Should they be stored as separate signals and merged during scoring, or used to recompute the base scores?
- [Technical] What cron mechanism should be used? (Symfony Cron bundle, system crontab, or Swoole-based scheduler?)
- [Technical] Should generation run synchronously (CLI/admin trigger) or async via Messenger worker for long-running jobs?
