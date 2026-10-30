# Watch Parties

Watch parties coordinate video playback through authenticated HTTP APIs and a dedicated WebSocket connection. A client must create or join the party and apply its playback updates. The current web application has no watch-party creation or joining screen; these endpoints are for clients that implement the party workflow.

A party does not replace access to source media, [transcoding](transcoding.md), or its independent worker. Establish ordinary playback first, then investigate party membership and synchronization separately.

## How It Works

1. The **host** creates a session and selects media to watch
2. Other users **join** the session through the HTTP API or the `party.join` WebSocket message
3. Playback is **synchronized** — when the host plays, pauses, or seeks, all participants follow
4. Each member can adjust their own audio and subtitle preferences independently

## Creating a Session

```
POST /api/party/sessions/
```

The host supplies the video public ID, optionally a transcode job public ID, and an optional member limit. The limit defaults to 10 and accepts 2 through 50. A successful creation returns `201` and the session public ID; the host is automatically a member. Use that public ID in subsequent routes, not an internal UUID.

The host must be allowed to play the video: administrators can play any video, other users only videos in libraries they have access to. The request may also name a transcode job, which must encode that video. A video or job that does not exist, or that the host may not play, returns `404 Not Found`. A job that encodes a different video returns `422 Unprocessable Entity`.

Deleting a video ends its sessions. Deleting a transcode job, for example during orphaned-job cleanup, clears the session's `transcodeJobId`. The session continues and keeps its members in sync; play, pause and seek then no longer signal an encoder.

## Joining and Leaving

- **Join**: `POST /api/party/sessions/{publicId}/join`
- **Leave**: `POST /api/party/sessions/{publicId}/leave`
- **List sessions**: `GET /api/party/sessions/`

A successful join creates or reconnects the member. An inactive, missing or full session cannot admit a new join. Read `GET /api/party/sessions/{publicId}` to inspect session state and its members. Joining a party is separate from opening a media stream.

## During a Session

- **Playback sync**: `POST /api/party/sessions/{publicId}/sync` — clients periodically report their playback position
- **Transfer host**: `POST /api/party/sessions/{publicId}/members/transfer-host` — the host can transfer control to another member
- **Member preferences**: `PATCH /api/party/sessions/{publicId}/members/me` — adjust audio/subtitle settings
- **End session**: `DELETE /api/party/sessions/{publicId}` — only the host can end a session

## Real-Time Communication

Watch parties use `/ws/party/{sessionPublicId}` for real-time sync. The HTTP information route returns connection details; the web server handles the actual WebSocket upgrade. Clients send `party.join` with the session public ID to establish membership and join its broadcast room, even when they already joined over HTTP. A connected socket alone does not establish that a client is applying host controls or playing media. If updates fail, check session membership, client behavior and the WebSocket connection through [Monitoring](monitoring.md) and [Troubleshooting](troubleshooting.md). See the [Real-Time Patterns](../part-2-developer-guide/real-time-patterns.md) page in the Developer's Guide for implementation details.

## Notes

- Only authenticated users can create or join sessions
- The host controls playback — other members follow along
- Each member's audio/subtitle preferences are independent
