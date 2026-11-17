# Party

The Party context implements synchronized watch-party playback. A user creates a party session and becomes its host, the host invites the users who may join, and the members watch media together with wall-clock synchronization, jitter compensation, and WebSocket-based real-time state broadcasting. Only its members see a party. Every route and WebSocket message that names a party answers any other signed-in user as it answers for a party that does not exist, except that an invited user can join.

## Domain Models

| Model | Type | Purpose |
|-------|------|---------|
| `SyncedPartySession` | Aggregate Root | Party session with host, video, members, playback state, and jitter compensation |
| `PartyMember` | Model | A member within a party session |
| `PartyInvitation` | Model | The host's standing permission for one user to join one party session |
| `MemberRole` | Value Object (enum) | Role within a party (`Host`, `Member`) |
| `PlaybackAction` | Value Object (enum) | Playback command (`Play`, `Pause`, `Seek`, `Join`, `Leave`) |
| `PlaybackState` | Value Object (enum) | Current playback state (`Stopped`, `Playing`, `Paused`) |

`SyncedPartySession` and `PartyMember` keep their fields in the state objects `SyncedPartySessionState` and `PartyMemberState`, which each model's private constructor, `create()` and `reconstitute()` share, as the project's aggregate root convention requires. `PartyInvitation` follows the same convention with `PartyInvitationState`; its factory is `issue()`, and an invitation never changes after it is issued.

## Domain Events

| Event | Purpose |
|-------|---------|
| `PartySessionCreated` | Emitted when a new party is created |
| `MemberJoined` | Emitted when a user joins a party |
| `MemberLeft` | Emitted when a member's row is removed: the member left, or the host revoked their invitation |
| `PartySessionEnded` | Emitted when a party is closed |
| `PlaybackStateChanged` | Emitted when the host plays, pauses or seeks; carries the session's playback state right after the change |

Invitations raise no events of their own.

## Commands and Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `CreatePartySessionCommand` | `CreatePartySessionHandler` | Create a new party session; its creator becomes the host, with a member row and an invitation |
| `InvitePartyMemberCommand` | `InvitePartyMemberHandler` | Invite a user to a party (host only); inviting an invited user changes nothing |
| `RevokePartyInvitationCommand` | `RevokePartyInvitationHandler` | Delete a user's invitation and, when they had joined, their member row (host only) |
| `JoinPartySessionCommand` | `JoinPartySessionHandler` | Add an invited user to an existing party as a member, or reconnect a returning member, which a full party still admits |
| `LeavePartySessionCommand` | `LeavePartySessionHandler` | Remove a member from a party; a leaving host hands the session's host role to the earliest-joined remaining member |
| `StartPlaybackCommand` | `StartPlaybackHandler` | Begin playback for all members |
| `PausePlaybackCommand` | `PausePlaybackHandler` | Pause playback for all members |
| `SeekPlaybackCommand` | `SeekPlaybackHandler` | Seek to a specific position |
| `SyncPlaybackCommand` | `SyncPlaybackHandler` | Periodic playback state synchronization |
| `TransferHostCommand` | `TransferHostHandler` | Transfer the host role to another member |
| `EndPartySessionCommand` | `EndPartySessionHandler` | Close and end a party session |

### Membership Changes

Changes to a party's members, invitations and host are serialized on the party's row. `JoinPartySessionHandler`, `LeavePartySessionHandler`, `TransferHostHandler`, `InvitePartyMemberHandler` and `RevokePartyInvitationHandler` each run in `TransactionPortInterface::run` and begin with `PartySessionPortInterface::lockByUuid`. That call locks the `party_sessions` row with `FOR UPDATE` and returns the session as it is stored then, overwriting a copy the unit of work loaded before the lock. Two changes to one party therefore take turns, and the second decides on the host, the member rows and the invitations that the first committed. `CreatePartySessionHandler` writes the session, the host's member row and the host's invitation in one transaction without the lock: no other change can reach a party that is not committed yet.

Party events leave the handler after the transaction commits. `MemberLeft` is dispatched only when a member row was deleted, and before `PartySessionEnded` when a leave also ends the party. `LivePartyRoomListener` takes the member's sockets out of the party's room on `MemberLeft`; run inside the transaction, it would act before the delete is visible, and a socket joining meanwhile would stay in the room.

Two guarantees rest on this order. The party's host always holds a member row: concurrent leaves, a transfer and a revoke cannot leave the host role with a user who is no longer a member. Once a revoke has answered, the revoked user has no member row and no socket in the party's room, even when a join ran beside it. The WebSocket controller completes the second: after a socket enters the room on `party.join`, it reads the member row again and takes the socket out when the row is gone ([Rooms](../architecture/real-time-patterns.md#rooms)).

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `SyncedPartySessionRepositoryInterface` | Domain repository contract for `SyncedPartySession` persistence and queries | `SyncedPartySessionRepository` |
| `PartyMemberRepositoryInterface` | Domain repository contract for `PartyMember` persistence and queries | `PartyMemberRepository` |
| `PartyInvitationRepositoryInterface` | Domain repository contract for `PartyInvitation` persistence and queries | `PartyInvitationRepository` |
| `PartySessionPortInterface` | Session lifecycle operations (create, end, query) and the row lock that serializes membership changes (`lockByUuid`) | `PartySessionService` |
| `PartyMemberPortInterface` | Member operations (join, leave, role transfer) | `PartyMemberService` |
| `PartyInvitationPortInterface` | Invitation operations (invite, revoke, check, list by session) | `PartyInvitationService` |
| `PartyMediaAccessPortInterface` | Verifies that the host may play the session's video and that any named transcode job encodes it | `PartyMediaAccess` |
| `PartyPublicIdentityPortInterface` | Resolves public and internal identifiers for party sessions, members, invitations, users, videos, and transcode jobs | `PartyPublicIdentity` |
| `PlaybackSynchronizationPortInterface` | Wall-clock position calculation and jitter compensation | `PlaybackSynchronizer` |

## API Endpoints

REST endpoints use `/api/party/sessions`. Every session route parameter is `{publicId}` (a 21-character `PublicId`), and `PartySessionResource` exposes `publicId` rather than internal UUIDs.

Parties are invite-only. The session list returns only the active parties the caller hosts or has joined. A user joins a party as an existing member or with an invitation from its host; the public ID alone admits no one. Every other route that names a session answers `404` unless the caller has a member row in it, which the host holds from creation; an unauthenticated request gets `401`. [Answers by Caller](#answers-by-caller) gives each route's answer.

### Party Sessions

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/party/sessions/` | `PartySessionController` | List the caller's active parties |
| POST | `/api/party/sessions/` | `PartySessionController` | Create a new party |
| GET | `/api/party/sessions/{publicId}` | `PartySessionController` | Get a party session and its members (host and members only) |
| DELETE | `/api/party/sessions/{publicId}` | `PartySessionController` | End a party session (host only) |

### Party Members

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| POST | `/api/party/sessions/{publicId}/join` | `PartySessionController` | Join a party (members and invited users only) |
| POST | `/api/party/sessions/{publicId}/leave` | `PartySessionController` | Leave a party |
| GET | `/api/party/sessions/{publicId}/members/` | `PartyMemberController` | List party members (host and members only) |
| PATCH | `/api/party/sessions/{publicId}/members/me` | `PartyMemberController` | Update the current member's playback preferences |
| POST | `/api/party/sessions/{publicId}/members/transfer-host` | `PartyMemberController` | Transfer the host role (host only) |

### Party Invitations

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/party/sessions/{publicId}/invitations/` | `PartyInvitationController` | List the party's invitations, oldest first (host only) |
| PUT | `/api/party/sessions/{publicId}/invitations/{userPublicId}` | `PartyInvitationController` | Invite a user (host only) |
| DELETE | `/api/party/sessions/{publicId}/invitations/{userPublicId}` | `PartyInvitationController` | Revoke a user's invitation and remove them from the party (host only) |

An invitation is the host's permission for one user, named by public ID, to join the party. `PartyInvitationResource` gives it as `userId`, the invited user's public ID, and `invitedAt`. Invite and revoke answer `204` and are idempotent: inviting an invited user keeps the first invitation and its time, and revoking a known user answers `204` whether or not an invitation existed.

Only the party's current host manages its invitations. They belong to the party, not to the host who issued them: after the host role moves, the new host manages every one, including the invitation of the party's creator. The invite and revoke handlers resolve `{userPublicId}` only after they know the caller is the host, so only the host can learn from these routes which users exist.

An invitation lasts until the host revokes it or until its party or its user is deleted, which removes the row by cascade. Joining and leaving do not consume it, so an invited user who leaves can join again. The creator holds an invitation from the party's creation and can likewise return after handing the host role to another member and leaving. Ending a party changes no membership and no invitation: its members still read and leave it, and its invitation routes keep working.

A revoke deletes the user's invitation and, when the user had joined, their member row. `RevokePartyInvitationHandler` then dispatches `MemberLeft`, on which the user's sockets leave the party's room and the remaining members receive a `party.member_event` leave.

The context leaves four things out:

- No route shows a user the invitations they hold, and an invited user cannot read a party before joining it. The host passes the party's public ID to the invited users outside Baander.
- A revoked socket gets no message of its own. It stops receiving the party's broadcasts, as the socket of a member who left over HTTP does.
- An invitation is not checked against library access, and neither is a join. Streams are authorized for each viewer by `AuthorizedPlayback` in the Transcode context, so an invitation gives no access to the party's video.
- Invitations per party are not capped. `maxMembers`, at most 50, bounds who is present.

### Playback

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| POST | `/api/party/sessions/{publicId}/sync` | `PartySessionController` | Sync playback state |

### Answers by Caller

The table gives the answer of each route that names a session, by caller. Paths are relative to `/api/party/sessions`, and a caller who is not signed in gets `401` from every route.

| Route | Signed-in non-member | Member, not host | Host |
|-------|----------------------|------------------|------|
| `GET /{publicId}`, `GET /{publicId}/members/`, `PATCH /{publicId}/members/me` | `404` | `200` | `200` |
| `POST /{publicId}/join` | Uninvited: `404`. Invited: `200`, or `409` when the party is ended or full | `200`, or `409` when the party is ended | `200`, or `409` when the party is ended |
| `POST /{publicId}/leave` | `404`, and no broadcast | `200` | `200` |
| `POST /{publicId}/sync` | `404` | `200` | `200` |
| `DELETE /{publicId}` | `404` | `403` | `200` |
| `POST /{publicId}/members/transfer-host` | `404` | `403` | `200`; `404` `User not found.` for an unknown user; `422` for a target who is not a member or is the host themself |
| `PUT`, `DELETE /{publicId}/invitations/{userPublicId}` | `404` | `403` | `204`; `404` `User not found.` for an unknown user; `422` when the host names themself |
| `GET /{publicId}/invitations/` | `404` | `403` | `200` |

Every `404` in the non-member column is the response a missing party gets, with the body `{"error":{"message":"Resource not found.","code":404}}`, and the request changes and broadcasts nothing. Neither the status nor the body lets a non-member tell a party they are not invited to from one that does not exist, whether that party is active, ended or full. The host's `404` for an unknown user is a different answer: it carries `User not found.`, and only the host can reach it.

A non-member takes the missing-party path by construction. Every route except join resolves the party and applies `PartyMembershipTrait::belongsTo` first, and answers a caller without a member row with `notFound()` before it dispatches a command. Join cannot decide in front of the dispatch, because its decision needs the row lock of [Membership Changes](#membership-changes). `JoinPartySessionHandler` decides under the lock and refuses a user who holds neither a member row nor an invitation with `PartyNotFoundException`. That exception's message, `Resource not found.`, is not translated, so `ExceptionSubscriber` renders the body `notFound()` returns in every language.

Refusals use the shared outcome exceptions: `NotFoundException` for `404`, `ConflictException` for `409`, `InvalidInputException` for `422`, and Symfony's `AccessDeniedException` for `403`. No party route answers a refusal with `500`. A member returning to a full party is let in; an invited user who is not yet a member gets `409` from it.

### WebSocket

| Path | Controller | Purpose |
|------|------------|---------|
| `GET /api/ws` | `WebSocketController` (Shared) | The application WebSocket, which carries the `party.*` messages |

A member or invited user requests a single-use ticket from `POST /api/ws/tickets`, upgrades at `/api/ws`, then sends `party.join` with the session's public ID as `sessionId` to enter the party's room. `party.join` applies the join rule of the HTTP route, and every other `party.*` message answers a user without a member row as it answers for a party that does not exist; [Rooms](../architecture/real-time-patterns.md#rooms) and the message table after it give the error texts. The room receives `party.member_event` when a member joins with `party.join`, leaves or is revoked, and `party.playback_state` after each play, pause or seek by the host, with `sessionId` (the public ID), `playbackState`, `currentPosition`, `wallClockPosition` and `updatedAt`. A play, pause or seek from anyone else broadcasts nothing. See [Real-Time Patterns](../architecture/real-time-patterns.md#party-sync-protocol) for the protocol.

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|-------------|
| Depends on | Shared | Uses `Uuid`, `PublicId`, `TransactionPortInterface`, and `LivePartyRoomsPortInterface` |
| Depends on | Catalog | `PartyPublicIdentity` translates video IDs by reading Catalog's `videos` table with SQL; no Catalog class is imported |
| Depends on | Transcode | References video playback access (`PlaybackAccessInterface`) and transcode job data; the start, pause and seek handlers raise Transcode's `PlaybackPositionChanged` |
| Depends on | Auth | User identification and authentication |

## Infrastructure

### Doctrine Entities

| Entity | Purpose |
|--------|---------|
| `SyncedPartySessionEntity` | Party session persistence |
| `PartyMemberEntity` | Party member persistence |
| `PartyInvitationEntity` | Party invitation persistence (`party_invitations`): one row per invited user and session, inserted and deleted but never updated |
| `PartyEventEntity` | Audit log of party events |

### Services

| Service | Purpose |
|---------|---------|
| `PartySessionService` | Session lifecycle management |
| `PartyMemberService` | Member management |
| `PartyInvitationService` | Invitation management |
| `PartyMediaAccess` | Checks video playback access and transcode job ownership via `PlaybackAccessInterface` |
| `PartyPublicIdentity` | Translates between internal `Uuid` values and 21-character `PublicId` values |
| `PlaybackSynchronizer` | Wall-clock sync with jitter compensation (`src/Party/Infrastructure/PlaybackSynchronizer.php`) |
| `LivePartyRoomListener` | Removes members who left or were revoked from live WebSocket party rooms on `MemberLeft`, closes rooms on `PartySessionEnded`, and sends `party.playback_state` to the room on `PlaybackStateChanged` |

### WebSocket

The shared `WebSocketController` dispatches the party commands for `party.*` messages and broadcasts member joins to the party's room. `SwooleLivePartyRooms`, called by `LivePartyRoomListener`, broadcasts leaves and the host's playback state.
