# Watch Parties

Watch parties let multiple users watch the same media together with synchronized playback. One user hosts the session, others join, and everyone's playback stays in sync.

## How It Works

1. The **host** creates a session and selects media to watch
2. Other users **join** the session via the API or WebSocket
3. Playback is **synchronized** — when the host plays, pauses, or seeks, all participants follow
4. Each member can adjust their own audio and subtitle preferences independently

## Creating a Session

```
POST /api/party/sessions
```

The host creates a session by specifying the media to watch and an optional member limit. The response includes the session's public ID for sharing.

The host must be allowed to play the video: administrators can play any video, other users only videos in libraries they have access to. The request may also name a transcode job, which must encode that video. A video or job that does not exist, or that the host may not play, returns `404 Not Found`. A job that encodes a different video returns `422 Unprocessable Entity`.

Deleting a video ends its sessions. Deleting a transcode job, for example during orphaned-job cleanup, clears the session's `transcodeJobId`. The session continues and keeps its members in sync; play, pause and seek then no longer signal an encoder.

## Joining and Leaving

- **Join**: `POST /api/party/sessions/{uuid}/join`
- **Leave**: `POST /api/party/sessions/{uuid}/leave`
- **List sessions**: `GET /api/party/sessions`

## During a Session

- **Playback sync**: `POST /api/party/sessions/{uuid}/sync` — clients periodically report their playback position
- **Transfer host**: `POST /api/party/sessions/{uuid}/members/transfer-host` — the host can transfer control to another member
- **Member preferences**: `PATCH /api/party/sessions/{uuid}/members/me` — adjust audio/subtitle settings
- **End session**: `DELETE /api/party/sessions/{uuid}` — only the host can end a session

## Real-Time Communication

Watch parties use a dedicated WebSocket endpoint for real-time sync. See the [Real-Time Patterns](../part-2-developer-guide/real-time-patterns.md) page in the Developer's Guide for implementation details.

## Notes

- Only authenticated users can create or join sessions
- The host controls playback — other members follow along
- Each member's audio/subtitle preferences are independent
