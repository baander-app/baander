All verification passes. Here's my report.

## Design Decision: Audio Folding

**One long VIDEO process via the manager; audio stays on the existing pool path.**

FFmpeg's HLS muxer (`-f hls`) produces exactly one rendition (video OR one audio track) per process invocation. To produce video + multiple audio languages in a single process, you'd need either:
- Multiple HLS outputs via `ffmpeg ... output1.m3u8 output2.m3u8` — but the HLS muxer doesn't support `-map` per-output cleanly in a single process
- The DASH muxer (`-f dash`) which supports multiple adaptation sets — but that's a different output format, not HLS

Given this constraint, I implemented: **the manager spawns ONE long video process** (video-only via `-an`), which is where the seek/init tax is highest and the long-process benefit is greatest. Audio continues through the existing per-segment pool dispatch (`encode_audio_segment`), which already works and passes all e2e tests. This is documented in the `TranscodeStreamManager` class docblock and in `SegmentEncoder::buildStreamArgs()` docblock.