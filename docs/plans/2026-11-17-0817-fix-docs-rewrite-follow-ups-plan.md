---
title: Docs Rewrite Follow-ups - Plan
type: fix
date: 2026-11-17
topic: docs-rewrite-follow-ups
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Docs Rewrite Follow-ups - Plan

## Goal Capsule

- **Objective:** Watch-party session details are readable only by the party's own signed-in members, a party works as its code promises (members follow the host's playback, the host role survives the host leaving, a member who drops can rejoin a full party), and the configuration, code comments and side documents say only what the code does.
- **Means:** Fix the open items recorded under "docs-book rewrite" in `ROADMAP.md`, through the decisions below (KTD1 to KTD6).
- **Product authority:** This plan and its Key Decisions. Watch parties are server-side groundwork with no client yet; keep them correct and never remove them (`AGENTS.md`, standing decisions).
- **Execution profile:** PHP application code in `src/Party/`, `src/Shared/`, `src/Filesystem/`, `config/`, the env files and `docker-compose.yml`; the tooling in `packages/baander-tooling/`; the committed `openapi.json` and the generated web client; and documentation in `docs-book/`, `dev-docs/`, `src/*/README.md`, `.agents/rules/` and `packages/baander-tooling/README.md`. No schema change.
- **Finish:** `ce-work` on a branch named after this plan, units as local commits; the user has authorized merging and pushing, and publishing the site once the docs change.
- **Stop conditions:** Stop and ask if the party broadcast needs a protocol or client contract beyond one new server message type, if listing a caller's own parties needs a schema change, or if admitting `VIDEO_ENCODER` to the worker requires changing how the deployment profile selects hardware.
- **Open blockers:** None.

---

## Product Contract

### Summary

Make watch parties invite-only and delete the unauthenticated party info route, broadcast host playback to the party's room, hand the host role on when the host leaves, let a reconnecting member back into a full party, remove the configuration no code reads, let `VIDEO_ENCODER` reach the media worker, and correct the stale text and two small console defects found during the docs-book rewrite.

### Problem Frame

The docs-book rewrite checked every page against the code and found defects the pages could only describe. `GET /ws/party/{sessionPublicId}/` sits outside the `^/api/` firewall, so anyone holding a party's public ID reads its session without signing in, and the `wsUrl` it returns points at a path the WebSocket handshake refuses. Inside the firewall, any signed-in user can list every active party and read its details and members. A host's play, pause and seek change the session but reach no member: the controller ends with a comment to "rely on client polling". When the host leaves, the promoted member gets the host role but the session keeps the departed user as host, so the departed user still steers playback and the new host cannot. A member who drops from a full party cannot rejoin, because the join handler counts members before it looks for the returning one. The env files carry 32 variables no code reads, Compose publishes a UDP port nothing listens on, and an operator's `VIDEO_ENCODER` never reaches the media worker. Code comments, rules, READMEs and OpenAPI descriptions still describe the removed task-worker transport and other behaviour the book corrected.

### Key Decisions

- **Watch parties are invite-only.** The party's public ID works as the invite: anyone signed in can join with it, but only the host and members see the party. (session-settled: user-directed — chosen over keeping listing and reading open to every signed-in user: the user picked invite-only so that a party's details need a signed-in member.) Governs R1, R12.
- **No replacement connection endpoint.** The old route goes; the party documentation and the session route's API description name the socket instead. (session-settled: user-directed — chosen over a members-only `/connection` endpoint: it would repeat the session route plus a fixed URL, and no client needs it.) Governs R2, R3.

### Requirements

**Party access**

- R1. Party session details and member lists are served only inside the API firewall, to the session's host or members; anyone else gets `404`, and an unauthenticated request gets `401`.
- R12. The session list shows only the parties the caller hosts or has joined; joining with a party's public ID stays open to any signed-in user.
- R2. The party documentation and the session route's API description name the socket the server accepts: `/api/ws`, reached with a ticket from `POST /api/ws/tickets`.
- R3. The old `/ws/party/{sessionPublicId}/` route no longer exists, and no path outside the API firewall serves party session data.

**Party behaviour**

- R4. When the host plays, pauses or seeks, every socket in the party's room receives the session's new playback state; a refused action from anyone else broadcasts nothing.
- R13. When the host leaves, the promoted member becomes the session's host, so that member's play, pause and seek apply and broadcast, and the departed user's do not.
- R5. A member already in a session can rejoin it when it is full; a new user still cannot join a full session.

**Configuration**

- R6. `.env`, `.env.example` and the development tooling set no variable that no code, configuration or script reads.
- R7. Compose publishes no port that nothing in the server listens on.
- R8. An operator's `VIDEO_ENCODER` reaches the media worker's hardware prober.

**Text and small defects**

- R9. Code comments, `.agents/rules/`, `src/*/README.md`, `dev-docs/`, OpenAPI descriptions, `#[AsCommand]` descriptions and option help say only what the code does.
- R10. `app:monitor:prune --days` validates its value as the console rules require, and `app:watch-files` without `--path` watches the current directory as it announces.
- R11. Every docs-book page that describes the changed behaviour matches it.

### Scope Boundaries

**Outside this work**

- The playback snapshot exposing UUID primary keys, the Node version for the Electron tests, the never-raised domain events behind the notification categories, the four unemitted Prometheus gauges, and `app:album:duplicates` taking a library UUID; `ROADMAP.md` keeps them open.
- The Electron client's LAN discovery scanner stays: it is client groundwork, and removing the server's port publication does not touch it.
- Any web or desktop client for watch parties.

### Sources / Research

- `src/Party/Interface/Controller/PartyWebSocketController.php` (no callers in `ui/` or `src/`, absent from `openapi.json`); `src/Party/Interface/Controller/PartySessionController.php` (`index` lists every active session, `show` has no membership check); `PartyMemberController::index` (no membership check); `PlaybackSynchronizer::synchronize()` (the existing membership rule through `PartyMemberPortInterface::findByUserAndSession()`); `config/packages/security.yaml:24-44` (firewalls and the dead `^/api/docs` rule); `SpaController`'s `/{path}` catch-all (`^(?!api).+`, priority -10), which serves the SPA shell for an unknown non-API path.
- `src/Shared/Interface/Controller/WebSocketController.php:426-511` (party playback and the polling comment); `src/Party/Infrastructure/EventListener/LivePartyRoomListener.php` and `src/Shared/Infrastructure/Swoole/SwooleLivePartyRooms.php` (the room broadcast for leaves); `StartPlaybackHandler` (dispatches `PlaybackPositionChanged` only with a transcode job); `LeavePartySessionHandler` (promotes a member without `transferHost`; `TransferHostHandler` calls both).
- `src/Party/Application/CommandHandler/JoinPartySessionHandler.php:34-44` (capacity before the returning member).
- `git grep` of every `.env`/`.env.example` key outside the env files and docs finds no reader for the 19 `SCANNER_*`, `DISCOVERY_ENABLED` (only written by `packages/baander-tooling/src/Development/Runtime.php:99`), `DISCOVERY_PORT`, `DISCOVERY_SERVER_NAME`, the three `ARGON2ID_*`, the four `TOKEN_BINDING_*`, `MUSICBRAINZ_VERSION`, `MUSICBRAINZ_CONTACT` and `LASTFM_API_SECRET`: 32 keys. `MAIL_FROM_NAME` is named only by the worker lists; the From header uses `%app.name%` (`config/packages/mailer.yaml:10`), and `tests/Integration/ProductNameConfigurationTest.php` sets it on purpose to prove it changes nothing.
- `config/services.yaml:1138-1144` and `HardwareCapabilitiesProber.php:36-118` (`VIDEO_ENCODER` picks the encoder; `HW_BITRATE_MULTIPLIER` and `HW_ACCEL_DEVICE` are read beside it). The worker environment passes three lists: `PresetRenderer::workerEnvironment()` (`packages/baander-tooling/src/Deployment/PresetRenderer.php:34-40`), the `$allowed` list in `packages/baander-tooling/src/Development/Workers.php:84-98`, and `DeploymentRuntimeEnvironment::ALLOWED_KEYS`, which throws on a key it does not admit. `bin/worker-media.php:6` (the media child inherits configuration); `.dockerignore:38-40` (only `.env` is baked into the image).
- The Frontend CI "Generated client drift" step (`yarn generate:check` in `ui/web`) fails when `openapi.json` changes without regenerating `ui/web/src/shared/api-client/gen/`.

---

## Planning Contract

### Key Technical Decisions

- KTD1. **Membership is checked in the party controllers through the member port.** `show` and the members list answer `404` unless the caller is the session's host or has a member row, using the rule `PlaybackSynchronizer` already applies; `index` lists only the caller's sessions. `PartyWebSocketController` is deleted. Governs R1, R3, R12.
- KTD2. **Host playback broadcasts through a Party event and the existing room listener.** The start, pause and seek handlers dispatch a new `PlaybackStateChanged` Party event after a host action changes the session, independent of the transcode job. `LivePartyRoomListener` sends it as `party.playback_state` through a new `LivePartyRoomsPortInterface::broadcastPlayback()`, mirroring the leave path. The message carries the session public ID, `playbackState`, `currentPosition`, `wallClockPosition` and `updatedAt`, as `PartySessionResource` names them. Governs R4.
- KTD3. **The join handler looks for the returning member before it checks capacity.** Governs R5.
- KTD4. **Unread configuration is deleted, not documented.** The pre-release policy allows it. The 32 keys leave both env files, the `DISCOVERY_ENABLED` override leaves `Runtime.php`, `MAIL_FROM_NAME` leaves the env files and all three worker lists with their comments corrected, and the `41234/udp` publication leaves `docker-compose.yml`. The docs rows that say "not read" go with them. Governs R6, R7.
- KTD5. **`VIDEO_ENCODER` joins all three worker lists together.** Add it, and `HW_BITRATE_MULTIPLIER` and `HW_ACCEL_DEVICE` if the prober reads them in the worker, to `PresetRenderer::workerEnvironment()`, the development `Workers.php` list and `DeploymentRuntimeEnvironment::ALLOWED_KEYS` in the same change, because the last one throws on a key the tooling forwards but it does not admit. Governs R8.
- KTD6. **Leaving hands the session host to the promoted member.** `LeavePartySessionHandler` calls `transferHost()` beside `promoteToHost()`, as `TransferHostHandler` does. Governs R13.

### Assumptions

- A1. No client depends on `/ws/party/...` or on the full session list (none in `ui/web` or `ui/electron`; the old route is absent from `openapi.json`).
- A2. A `party.playback_state` broadcast needs no client change, because no client for watch parties exists yet.

### Sequencing

U1, U2 and U3 touch Party code and land in that order. U5 follows U1, because both regenerate `openapi.json` and the generated client; regenerate once after U5's description changes. U4 is independent. U6 runs last.

---

## Implementation Units

### U1. Make parties invite-only and delete the old route

- **Goal:** Only a party's host and members see it, and nothing outside the API firewall serves it.
- **Requirements:** R1, R2, R3, R12, R11; KTD1.
- **Dependencies:** None.
- **Files:** Delete `src/Party/Interface/Controller/PartyWebSocketController.php`. Modify `src/Party/Interface/Controller/PartySessionController.php` (`index`, `show`, the API description naming `/api/ws` and `POST /api/ws/tickets`) and `src/Party/Interface/Controller/PartyMemberController.php`; add a session-by-member lookup to the party ports if `index` needs one. Regenerate `openapi.json` (`composer openapi`, 2G memory limit) and `ui/web/src/shared/api-client/gen/` (`yarn generate` in `ui/web`). Test: `tests/Functional/Controller/PartySessionAccessTest.php` (new). Docs: `src/Party/README.md`, `docs-book/part-1-operator-guide/media/watch-parties.md` (the "List sessions" line, the socket), `docs-book/part-2-developer-guide/contexts/party.md`.
- **Test scenarios:**
  - An unauthenticated `GET /api/party/sessions/{id}` answers `401`.
  - A signed-in user who is neither host nor member gets `404` from the session and from its member list.
  - The host and a member get `200` from both.
  - The session list returns the caller's hosted and joined parties and omits another user's party.
  - A non-member can still join with the public ID, and then reads the session.
  - `GET /ws/party/{id}/` returns no party session data: the router has no party WebSocket route and the response is not a JSON session body.
- **Verification:** The functional tests pass; `openapi.json` and the generated client no longer name `/ws/party` and pass their drift checks.

### U2. Broadcast host playback and hand the host role on

- **Goal:** Every member's socket learns the host's play, pause and seek, including after the original host leaves.
- **Requirements:** R4, R13, R11; KTD2, KTD6.
- **Dependencies:** None.
- **Files:** Create `src/Party/Domain/Event/PlaybackStateChanged.php`. Modify the start, pause, seek and leave handlers in `src/Party/Application/CommandHandler/`, `src/Party/Infrastructure/EventListener/LivePartyRoomListener.php`, `src/Shared/Application/Port/LivePartyRoomsPortInterface.php`, `src/Shared/Infrastructure/Swoole/SwooleLivePartyRooms.php` and `src/Shared/Interface/Controller/WebSocketController.php` (remove the polling comment). Tests: `tests/Unit/Party/Application/CommandHandler/{Start,Pause,Seek,LeavePartySession}*HandlerTest.php`, `tests/Unit/Party/Domain/Event/DomainEventRoundTripTest.php`, a listener test beside the existing Party tests, `tests/Functional/Party/PartyLiveRoomWiringTest.php`. Docs: `docs-book/part-2-developer-guide/architecture/real-time-patterns.md`, `watch-parties.md`, `contexts/party.md`.
- **Execution note:** Start with the handler tests asserting the event for the host and its absence for anyone else.
- **Test scenarios:**
  - A host play dispatches `PlaybackStateChanged` with or without a transcode job.
  - Another user's play, pause or seek dispatches nothing.
  - After the host leaves, the session's host is the promoted member; that member's play dispatches the event, and the departed user's dispatches nothing.
  - The listener sends `party.playback_state` with the session's public ID and state to the room.
  - The room port does nothing outside the server, as `removeMember()` does.
  - The wiring test proves the listener is registered for the new event.
- **Verification:** Party unit and functional tests pass, and the real-time patterns page describes the broadcast.

### U3. Let a returning member rejoin a full party

- **Goal:** A dropped member is not refused by their own party.
- **Requirements:** R5, R11; KTD3.
- **Dependencies:** None.
- **Files:** Modify `src/Party/Application/CommandHandler/JoinPartySessionHandler.php`. Test: `tests/Unit/Party/Application/CommandHandler/JoinPartySessionHandlerTest.php`. Docs: `watch-parties.md`.
- **Execution note:** Add the failing reconnect test first.
- **Test scenarios:**
  - A full session readmits an existing member and calls `reconnect()`.
  - A full session refuses a new user.
  - A session with room still adds a new user and dispatches `MemberJoined`.
- **Verification:** The handler tests pass.

### U4. Remove unread configuration and pass `VIDEO_ENCODER` to the worker

- **Goal:** The configuration holds only what the code reads, and an operator's encoder choice reaches the media worker.
- **Requirements:** R6, R7, R8, R11; KTD4, KTD5.
- **Dependencies:** None.
- **Files:** Modify `.env`, `.env.example`, `docker-compose.yml`, `src/Shared/Infrastructure/Worker/DeploymentRuntimeEnvironment.php`, `packages/baander-tooling/src/Deployment/PresetRenderer.php`, `packages/baander-tooling/src/Development/Workers.php`, `packages/baander-tooling/src/Development/Runtime.php`. Tests: the `DeploymentRuntimeEnvironment` unit test under `tests/Unit/Shared/`, and the tooling preset deployment test under `scripts/tests/php/`. Docs: `configuration/environment-variables.md`, `configuration/authentication-and-limits.md`, `maintenance/security.md`, `workers/worker-deployment.md`, `media/transcoding.md`, `media/external-apis.md`, and `getting-started/storage-and-data.md` if it lists a removed key.
- **Approach:**
  1. Re-run the `git grep` per key immediately before deleting it, and keep any key that gained a reader.
  2. Delete the keys and their comment blocks, the `DISCOVERY_ENABLED` line in `Runtime.php`, and the `41234/udp` line.
  3. Edit the three worker lists in one change (KTD5); remove `MAIL_FROM_NAME` from all three and correct the allowlist comment.
  4. Remove the "not read" rows and notes from the docs, and state that `VIDEO_ENCODER` reaches the media worker.
  5. Run `.php-cs-fixer.tooling.php` on the touched tooling files.
- **Test scenarios:**
  - `DeploymentRuntimeEnvironment` admits `VIDEO_ENCODER` and refuses `MAIL_FROM_NAME`.
  - A preset environment carrying `VIDEO_ENCODER` yields a worker environment that carries it and does not carry `MAIL_FROM_NAME`.
- **Verification:** The unit and tooling tests pass; `git grep` finds no reference to a removed key outside history, except the obsolete-name inputs in `ProductNameConfigurationTest`, which stay.

### U5. Correct stale text and the two console defects

- **Goal:** Side documents and code text match the code; two commands behave as documented.
- **Requirements:** R9, R10, R11.
- **Dependencies:** U1 (shared `openapi.json` and generated client).
- **Files:**
  - Comments: `src/Library/Interface/Console/ScanLibraryCommand.php`, `src/Media/Interface/Console/PruneMissingImagesCommand.php`, `src/Library/Application/Service/LibraryScanClaims.php`, `src/Shared/Interface/WebSocket/WebSocketRoomPolicy.php`, `config/packages/framework.yaml`, the `DrainBudget` class.
  - Rules and docs: `.agents/rules/admin-cli-parity.md`, `src/{Playlist,Catalog,Auth,Session,Transcode}/README.md`, `dev-docs/context-map.md`, `dev-docs/worker-runtime-inventory.md`, `packages/baander-tooling/README.md`.
  - Descriptions: `MonitorJobShowCommand`, the `app:e2e:ingest-video` command, `LibraryMember{Grant,List,Revoke}Command`, `SweepTranscodeCacheCommand`, the four Session resources naming `incarnationId`, `TokenResource`; regenerate `openapi.json` and the generated web client after these edits.
  - Config: the dead `^/api/docs` access rule in `config/packages/security.yaml`.
  - Defects: `src/Shared/Interface/Console/PruneJobMonitorsCommand.php` (use `AdminCommandSupport::integerOption`), `src/Filesystem/Command/WatchFilesCommand.php` (watch `getcwd()` without `--path`).
  - Tests: `tests/Unit/Filesystem/Command/WatchFilesCommandTest.php`, a `PruneJobMonitorsCommand` test beside the other console tests.
  - Docs: `commands/app-watch-files.md`, `commands/app-monitor-prune.md`, `contexts/filesystem.md`.
- **Execution note:** Test-first for the two console defects.
- **Test scenarios:**
  - `app:watch-files` without `--path` registers a watch on the current directory.
  - `app:monitor:prune --days=abc` exits 2 with the option message, and `--days=5x` is refused instead of read as 5.
  - `app:monitor:prune --days=30` still deletes records older than 30 days.
- **Verification:** The new tests pass; `git grep -n swoole_task` finds no live claim outside history; the OpenAPI and generated-client drift checks pass.

### U6. Record the follow-ups

- **Goal:** `ROADMAP.md` closes the fixed items and keeps the rest open.
- **Requirements:** R11.
- **Dependencies:** U1 to U5.
- **Files:** `ROADMAP.md`.
- **Test expectation:** none; this is the delivery record.
- **Verification:** The "docs-book rewrite" open list names only the items this plan left out of scope, and a new section records the fixes with their checkpoint.

---

## Verification Contract

| Gate | Command | Applies to |
|---|---|---|
| Unit tests | `php -d memory_limit=2G vendor/bin/phpunit <touched tests under tests/Unit>` | U1-U5 |
| Functional tests | `BAANDER_TEST_IMAGE=<ci image> bash scripts/test-functional-container.sh <test files>`, per the testing guide | U1, U2 |
| Tooling tests | `php -d memory_limit=2G vendor/bin/phpunit -c phpunit-tooling.xml.dist <touched tests under scripts/tests/php>` | U4 |
| Tooling format | `composer check:tooling-format` after `.php-cs-fixer.tooling.php` on the touched tooling files | U4 |
| Static gates | PHPStan and Deptrac as the testing guide's backend runners page names them | U1-U5 |
| OpenAPI drift | `composer openapi` (2G memory limit), then the export check the testing guide names | U1, U5 |
| Generated client drift | `yarn generate:check` in `ui/web` | U1, U5 |
| Docs | `make website-build`, `corepack yarn test:browser` in `ui/site/` | U1-U5 docs |
| Whitespace | `git diff --check` | All |

## Definition of Done

- Every unit's tests exist and pass, and the gates above pass; any gate that could not run is reported with the reason.
- `ROADMAP.md` records the delivery with its checkpoint (suite counts, static gates).
- No code, comment or test cites plan IDs.
- The site is republished when docs-book pages changed, as the user authorized.
- Abandoned attempts and debugging aids are out of the diff.
