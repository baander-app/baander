# debug:hw-transcode

Show the resolved hardware encoder profile and sample FFmpeg commands. Boots the hardware capabilities prober, prints the active `EncoderProfile`, the FFmpeg input/decoder flags it produces, per-source-codec decoder resolution, sample encode commands for each quality tier, and a reference table of all hardware accelerators. Use this to verify that GPU detection and hardware transcoding are configured correctly.

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
- **Hardware Accelerator Reference** — every `HardwareAccelerator` case with its HEVC/H264 encoders, hwaccel method, hardware-tonemap support, and whether it needs a device path.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Prober booted and profile resolved successfully |

## Tips

- This is a diagnostics command in the `debug:` namespace — it makes no changes and is safe to run any time.
- Run it on-metal (or inside the container that has GPU access) to confirm the prober actually detects the hardware. A software profile where you expect hardware indicates a detection or configuration problem.
- The command takes no arguments; everything it reports comes from the resolved configuration and the prober's environment.
