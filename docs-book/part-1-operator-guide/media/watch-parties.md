# Watch Parties

Watch parties coordinate video playback through authenticated HTTP APIs and the application's WebSocket connection. A client must create or join the party and apply its playback updates. The current web application has no watch-party creation or joining screen; these endpoints are for clients that implement the party workflow.

A party does not replace access to source media, [transcoding](transcoding.md), or its independent worker. Establish ordinary playback first, then investigate party membership and synchronization separately.

## How It Works

1. The **host** creates a session and selects media to watch
2. The host **invites** each user who may join, by the user's public ID, and gives them the session's public ID
3. Invited users **join** the session, through the HTTP API or the `party.join` WebSocket message
4. Playback is **synchronized**: the host's play, pause and seek update the session, every connection in the party's room receives the new state, and participants correct drift through `sync`
5. Each member can adjust their own audio and subtitle preferences independently

## Creating a Session

```
POST /api/party/sessions/
```

The host supplies the video public ID, optionally a transcode job public ID, and an optional member limit. The limit defaults to 10 and accepts 2 through 50. A successful creation returns `201` and the session public ID; the host is automatically a member. Use that public ID in subsequent routes, not an internal UUID. The public ID alone admits no one: the host [invites](#invitations) each user who may join.

The host must be allowed to play the video: administrators can play any video, other users only videos in libraries they have access to. The request may also name a transcode job, which must be the host's own job of that video. A video or job that does not exist or that the host may not play, and a job another user owns, return `404 Not Found`. The host's own job of a different video returns `422 Unprocessable Entity`.

The job stays with the session while its owner is a member, so a member who becomes host keeps steering it. The session's `transcodeJobId` is cleared when the owner leaves, when the host revokes the owner's invitation, or when the job is deleted, for example during orphaned-job cleanup. The session continues and keeps its members in sync; play, pause and seek then no longer signal an encoder. Deleting the video ends its sessions.

## Invitations

- **Invite**: `PUT /api/party/sessions/{publicId}/invitations/{userPublicId}`
- **Revoke**: `DELETE /api/party/sessions/{publicId}/invitations/{userPublicId}`
- **List invitations**: `GET /api/party/sessions/{publicId}/invitations/`

The host decides who may join. An invitation names one user by public ID, and only the session's current host can invite, revoke or list. A member who is not the host gets `403 Forbidden` from all three routes. When the host role moves to another member, the new host manages every invitation of the session, including those an earlier host issued.

Invite and revoke return `204 No Content`. Inviting a user who is already invited changes nothing, and so does revoking a user who holds no invitation. A public ID that belongs to no user returns `404 Not Found` with the message `User not found.`, and the host cannot invite or revoke themself: that returns `422 Unprocessable Entity`. The list has one entry per invited user, oldest first, with the user's public ID as `userId` and the time of the invitation as `invitedAt`.

An invitation lasts until the host revokes it, or until its session or its user is deleted. Joining and leaving do not use it up: an invited user who leaves can join again without a second invitation. The session's creator holds an invitation from the session's creation, so a creator who hands the host role to another member and then leaves can also join again. Ending the session removes neither its members nor its invitations, and the invitation routes keep working afterwards.

Revoking the invitation of a user who has joined also removes that user from the session. Their membership ends, their WebSocket connections leave the session's room, and the remaining members receive the same leave event as when a member leaves.

Invitations have these limits:

- The server does not tell a user about an invitation, and an invited user cannot see the session before joining it: the session list shows only sessions the caller hosts or has joined. The host gives the session's public ID to the invited users by other means.
- A revoked user's open connections receive no message about the removal. They stop receiving the session's events.
- An invitation is not checked against library access. The host can invite a user who may not play the session's video. That user can join the session, but opening its media stream is authorized separately for each viewer.
- The number of invitations per session is not capped. The member limit bounds how many users are in the session at once.

## Joining and Leaving

- **Join**: `POST /api/party/sessions/{publicId}/join`
- **Leave**: `POST /api/party/sessions/{publicId}/leave`
- **List sessions**: `GET /api/party/sessions/` — only the active parties you host or have joined

Parties are invite-only. A user joins a session as an existing member or with an [invitation](#invitations), and a successful join creates or reconnects the member. A join by a user who holds neither returns `404 Not Found`, the answer for a session that does not exist, whether the session is active, ended or full. A member or invited user gets `409 Conflict` from joining an ended session. A full session answers an invited user with `409 Conflict`, but takes back a member who has not left it, such as one whose connection dropped. Joining a party is separate from opening a media stream.

The host and members read `GET /api/party/sessions/{publicId}` to inspect session state and its members. For any other signed-in user, that route and every other route that names a session return the `404 Not Found` of a session that does not exist; the one exception is a join by an invited user. A leave or a playback sync from a user who is not a member changes nothing and reaches no member.

## During a Session

- **Playback sync**: `POST /api/party/sessions/{publicId}/sync` — clients periodically report their playback position
- **Transfer host**: `POST /api/party/sessions/{publicId}/members/transfer-host` — the host can transfer control to another member. When the host leaves, the remaining member who joined earliest becomes the host.
- **Member preferences**: `PATCH /api/party/sessions/{publicId}/members/me` — adjust audio/subtitle settings
- **End session**: `DELETE /api/party/sessions/{publicId}` — only the host can end a session

## Real-Time Communication

Watch parties use the application's single WebSocket connection: a client obtains a single-use ticket from `POST /api/ws/tickets` and upgrades at `/api/ws`. The web server refuses an upgrade on any other path. Clients send `party.join` with the session public ID to establish membership and join its broadcast room, even when they already joined over HTTP. A `party.join` from a user who is neither a member nor invited gets the error `Invalid public identity`, the answer for a session that does not exist. After each play, pause or seek by the host, every connection in the room receives a `party.playback_state` message with the session's playback state and position; a play, pause or seek from anyone else changes nothing and sends nothing. A connected socket alone does not establish that a client is applying host controls or playing media. If updates fail, check session membership, client behavior and the WebSocket connection through [Monitoring](../operations/README.md) and [Troubleshooting](../operations/troubleshooting.md). See the [Real-Time Patterns](../../part-2-developer-guide/architecture/real-time-patterns.md) page in the Developer's Guide for implementation details.

## Notes

- Only authenticated users can create sessions, and only invited users can join them
- The host controls playback — other members follow along
- Each member's audio/subtitle preferences are independent
