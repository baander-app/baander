# Audio transcoding

Use this page to enable audio encoding, choose its bitrate and AAC implementation, and understand cached renditions. The delivery and worker prerequisites are in [Transcoding](transcoding.md).

## Audio Transcoding

Music playback first negotiates original, cached or copied delivery. If encoding
is needed, the `transcode` capability and the audio setting must both allow it. Direct API clients can
request an encoded format explicitly:

```
GET /api/stream/track?id=<track public ID>&format=opus
GET /api/stream/track?id=<track public ID>&format=mp3&bitrate=192000
```

`format` is `opus`, `aac` or `mp3`. `bitrate` is optional, in bits per second, and requires `format`. Baander checks the listener's access to the track before it transcodes anything, exactly as for the original file.

### Settings

Three [server settings](../configuration/server-settings.md#server-settings) govern audio transcoding. Baander reads them on every request. Video transcoding is outside the first two. The AAC implementation also covers the audio track of DASH video sessions. Under `transcode`, a direct format request while `transcode.enabled` is off receives `403`; enabling it still cannot authorize encoding under `remux`.

| Setting | Default | Effect |
|---------|---------|--------|
| `transcode.enabled` | `false` | Allows audio encoding only when the startup profile also permits it. Original and negotiated stream-copy delivery do not enable encoding. |
| `transcode.max_bitrate` | `320` | The highest bitrate, in kbps: `128`, `192`, `256` or `320`. A higher requested bitrate is lowered to it, and a request without a bitrate gets it. |
| `transcode.aac_implementation` | `bac` | Which codec implementation FFmpeg uses for AAC: `bac` (Bånder Audio Core, Bånder's own codec), `fdk_aac` (Fraunhofer's fdk-aac) or `ffmpeg` (FFmpeg's built-in codec). See [AAC implementation](#aac-implementation). |

Baander then rounds the bitrate down to the highest step of a fixed ladder that does not exceed it: 64, 96, 128, 160, 192, 256 and 320 kbps. A request below 64 kbps gets 64 kbps. The Opus ladder stops at 256 kbps, so an Opus stream never exceeds 256 kbps, whatever the maximum. Because only these steps exist, each track has at most seven renditions per format.

An unsupported format, a bitrate that is not a positive whole number, or a bitrate without a format gets `400`. A failed encode gets `500` and is logged with its reason.

Renditions are admitted through durable PostgreSQL runtime state. Under `transcode`, the media role runs as many audio encodes at once as its audio slots, 12 by default, independently of its video slots. Requests for the same source fingerprint, format and bitrate join the same accepted work. Web waits up to 60 seconds for output to start; an unavailable worker or failed generation produces an encode failure rather than an inline web encode.

When every audio slot stays busy for that whole wait, `/api/stream/track` and `/api/stream/original/track` answer `503` with `Retry-After: 5` and the reason `audio_slots_busy`. The work keeps its place in the queue, and the web player's retry follows it once a slot frees.

### AAC implementation

The FFmpeg in the application image carries three AAC codecs: Bånder Audio Core, the codec library Bånder develops in `packages/baander-audio-core`; Fraunhofer's fdk-aac; and FFmpeg's own. The `transcode.aac_implementation` setting selects which one encodes AAC renditions and the audio track of DASH video sessions, and which one decodes an AAC source for those encodes.

Bånder Audio Core is the default so that real playback exercises the codec before the desktop app depends on it; the other two positions are the operator's way back if a stream sounds wrong.

The setting applies to encodes only. Stream copies, probing and the decoder of
a source that is not AAC never change with it. A rendition reads the setting
when it starts. A DASH session fixes the implementation when it is created and
keeps it until the session ends, through pauses and seeks, so a change applies
to sessions created afterwards. Cached renditions and completed presentations
are neither re-encoded nor removed by a change; the cache sweep remains the way
to clear them.

A rendition that failed while encoding or decoding through one implementation
is encoded again on its next request once another implementation is selected,
so switching away from a faulty codec also recovers the tracks it broke. A
failed rendition that neither produced AAC nor read an AAC source stays failed.

Change it in the admin settings page under Media, or on the command line:

```bash
dc exec app php bin/console app:settings:set transcode.aac_implementation fdk_aac
```

When the FFmpeg binary in use lacks the selected implementation, for example an
operator's own FFmpeg path, an AAC encode fails before it starts with the reason
`AAC implementation "Bånder Audio Core" is not available in this FFmpeg build`;
[`debug:hw-transcode`](../commands/debug-hw-transcode.md) lists the implementations
the binary offers.

### Cached renditions

Each track, format and bitrate has one cached rendition under `CONVERT_STORAGE_PATH/audio-renditions/<track>/`. Each execution writes a generation directory; its file name includes a fingerprint of the source file, so a replaced source is encoded again.

The first request for a rendition starts the encode and streams the output while FFmpeg writes it. Its length is not known yet, so that response carries `Accept-Ranges: none` and the listener cannot seek. A second request for the same rendition while it is encoding joins that encode instead of starting another. Once the rendition is complete, later requests get the cached file with full byte-range support, so seeking works. Each encode has a worker-owned claim and generation.

Web follows only that accepted generation, checks live ownership while output is growing and accepts its final file only after completion is committed. Lost ownership or a changed generation cuts the stream as an error.

If a completed cache file has been evicted, a compare-and-swap against its
completed generation resets the same canonical runtime row to pending when the
requested execution is permitted. The next worker claim advances its generation
and rebuilds the file; concurrent readers do not create another canonical row.
Completed-artifact reuse under `remux` does not authorize an encoded rebuild.

The transcode cache sweep (`app:transcode:cache-sweep`) treats each track's renditions, all formats and bitrates together, as one cache unit under the same age limit and size budget as video segments. A track's renditions count as in use while an encode has written to a partial rendition recently.

### Web player

The web player negotiates with the receiving browser's capabilities before loading a track. If that source fails, it reports the failure to negotiation before trying an encoding fallback. An incompatible result stops playback with its explanation. When encoding is allowed, the existing Opus, AAC and MP3 fallback uses the server's `transcode.max_bitrate`. A growing encoded rendition has no byte-range seeking; the player restores position after reconnect once a seekable source is available.

Original and completed-file playback use native seeking; copied playback follows the actual output's seeking support.

When the web server restarts during a transcoded stream, the connection is cut as an error while the worker continues encoding. The next request joins the accepted rendition or reads its completed file. The player keeps the listener's position and continues once byte-range seeking is available; see [Listeners during a restart](../maintenance/web-server-restarts.md#listeners-during-a-restart).
