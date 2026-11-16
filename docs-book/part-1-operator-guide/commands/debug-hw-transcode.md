# debug:hw-transcode

Show the hardware encoder profile the media worker resolved, with sample FFmpeg commands. The command does not probe hardware itself. A media worker with video encoder slots probes the hardware when it starts and publishes what it found to `CONVERT_STORAGE_PATH/worker-encoder-capabilities.json`; the command reads that last observation and names the worker that made it and when. From it the command prints the active `EncoderProfile`, the FFmpeg input/decoder flags it produces, per-source-codec decoder resolution, sample encode commands for each quality tier, and a reference table of all hardware accelerators. Use this to verify that GPU detection and hardware transcoding are configured correctly.

## Quick start

```bash
make exec cmd="php bin/console debug:hw-transcode"
```

## What the command prints

The output is organized into sections:

- **Resolved Encoder Profile** — accelerator, encoder, decoder, hwaccel method/device/output format, whether the profile is hardware-backed, and the bitrate multiplier.
- **FFmpeg Input Flags** — the hwaccel flags and decoder flags the profile generates.
- **Decoder Resolution Per Source Codec** (hardware profiles only) — which decoder is selected for `h264`, `hevc`, `av1`, `mpeg2video`, and `vp9` sources.
- **Sample FFmpeg Commands** — a full init-segment encode command for each quality tier (720p, 1080p, 4K) against an h264 source.
- **AAC Implementations** — the three positions of the `transcode.aac_implementation` setting (`bac`, `fdk_aac`, `ffmpeg`), the FFmpeg codec name behind each, and whether the FFmpeg binary offers it as encoder and as decoder. A `No` means an encode that selects that position fails before it starts.
- **Hardware Accelerator Reference** — every `HardwareAccelerator` case with its HEVC/H264 encoders, hwaccel method, hardware-tonemap support, and whether it needs a device path.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The worker's last observation was read and printed |
| 1 | No worker observation is available, for example because no media worker with video encoder slots has started; the command prints `Worker encoder capabilities are unavailable` |

## Tips

- This is a diagnostics command in the `debug:` namespace — it makes no changes and is safe to run any time.
- The profile is the media worker's observation, so it reflects the GPU access of the worker container, not of the container the command runs in. The observation is not a live measurement: restart the worker after a hardware or configuration change. A software profile where you expect hardware indicates a detection or configuration problem.
- The command takes no arguments. Everything it reports comes from the worker's last observation and the resolved configuration, except the AAC rows: those come from the FFmpeg binary of the container the command runs in.
