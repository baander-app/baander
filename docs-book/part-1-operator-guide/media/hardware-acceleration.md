# Hardware acceleration

Configure and qualify the encoder on the worker deployment. Start with [Transcoding](transcoding.md) to confirm that the selected delivery path requires encoding and that the deployment permits it.

## Hardware Acceleration

A `transcode` worker encodes video in software unless the deployment profile names
a hardware encoder. A `remux` deployment cannot re-encode, so it accepts no
hardware encoder and skips encoder qualification.

Name the encoder in the managed preset's private configuration with a top-level
`hardware` entry beside `capability` and `size`:

```json
"hardware": {"accelerator": "vaapi", "device": "/dev/dri/renderD128"}
```

| Field | Meaning |
|-------|---------|
| `accelerator` | `vaapi`, `qsv`, `nvenc` or `amf` |
| `device` | The device node the encoder opens. `vaapi`, `qsv` and `amf` require one; `nvenc` takes none |
| `group` | Preset only, optional: the numeric group that opens the device. By default the controller reads the group that owns the device node on the Docker host |

A source install builds the same profile with
[app:deployment:profile:show](../commands/app-deployment-profile-show.md) and its
`--hardware-accelerator` and `--hardware-device` options, and sets the worker
manifest's `deviceGroup`; see [Worker deployment](../workers/worker-deployment.md).
The hardware encoder is part of the profile, so web, workers and the worker manifest
agree on it, and changing it changes the profile fingerprint. A hardware encoder
requires at least one video slot.

Only the execution worker receives the device, at the same path, and the device's
group as a supplementary group. Web and the control worker receive neither. `nvenc`
instead gives the execution worker the host's NVIDIA GPUs through the NVIDIA
container runtime, with the compute, utility and video capabilities; the host must
provide that runtime. Creation, start and readiness compare the execution
container's devices, groups and GPU requests with the admitted recipe. The
containers still carry no CPU or memory limits.

At worker start, media control runs a test encode with the accelerator's HEVC encoder on the configured device, at 640x360, the smallest tier the ladder encodes. When `VIDEO_ENCODER` names the same accelerator's H.264 encoder, such as `h264_vaapi`, media control tests and uses that one instead. If the test encode fails, media control stops instead of encoding in software, and the worker deployment does not become ready.

Its `worker_media_failure` log line then carries `"reason": "hardware_encoder_unavailable"` with the encoder, the device and the last lines of FFmpeg's output. Without a hardware encoder, the worker qualifies the software encoder that `VIDEO_ENCODER` names when it is `libx264`, `libx265` or `libsvtav1`, and `libx265` otherwise. `VIDEO_ENCODER` alone never selects a hardware encoder.

Set `VIDEO_ENCODER` in the managed preset's `environment` object, or in the
`runtimeEnvironment` of the [worker credentials](../workers/worker-deployment.md#credentials-format)
for a source install. Either value reaches the media worker and overrides the one in
the image's `.env`.

With a hardware encoder, unset video slots take the measured hardware default of
12. Audio stays at 12, and the video maximum still follows the pooler bound: at the
16 GB starting point, 145 slots without audio slots, or 137 beside the 12 audio slots.

The default of 12 follows `hevc_vaapi`, the encoder media control picks by default. On an AMD Radeon RX 9070 XT with VAAPI, every 1080p stream ran at 1.12x real time or faster with 12 streams, and at 1.08x or faster with 14. `h264_vaapi` keeps up with fewer streams: 1.12x with 8 and 0.91x with 10. A deployment that sets `VIDEO_ENCODER` to `h264_vaapi` should therefore set `videoSlots` to 8 on that GPU. The video engine was fully busy at every step.

Each stream used about 0.03 CPU seconds per second of output up to 12 streams, and up to 0.063 at 14 and 16. The [hardware cost measurement](../../part-2-developer-guide/testing/media-encoder-cost.md#hardware-encoder-cost) records the figures. Other GPUs, codecs and sources sustain different counts, so set `videoSlots` after trying the host's own load.

The worker image carries Mesa's VAAPI driver, radeonsi, which serves AMD GPUs; it
carries no Intel VAAPI driver (iHD or i965). `qsv` also needs Intel's oneVPL runtime, `amf` AMD's AMF runtime and `nvenc`
NVIDIA's driver libraries, which the image does not include; their test encode fails
at start until the image provides them.

Under Docker's default seccomp profile, Mesa's amdgpu driver logs `amdgpu: os_same_file_description couldn't determine if two DRM fds reference the same file description` once per FFmpeg process. The profile refuses the `kcmp` system call that Mesa uses to compare descriptors unless the container holds `CAP_SYS_PTRACE`. Without that comparison, Mesa treats two descriptors of one opened device as distinct.

The difference matters only when Mesa passes buffers between them as kernel buffer handles, which FFmpeg's decode, scale and encode pipeline does not do: on the RX 9070 XT, 20-second H.264 and HEVC encodes produced bit-identical output with the default profile and without seccomp. Keep the default profile; do not add `CAP_SYS_PTRACE` or disable seccomp to silence the line.
