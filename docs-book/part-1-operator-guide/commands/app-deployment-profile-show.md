# app:deployment:profile:show

Print a deployment profile and the limits Baander derives from its capability and
size. A source install uses it to get the web environment value and the worker
manifest's `deploymentProfile` object without writing the JSON by hand.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

```bash
make exec cmd="php bin/console app:deployment:profile:show"
make exec cmd="php bin/console app:deployment:profile:show --video-slots=4 --audio-slots=12"
make exec cmd="php bin/console app:deployment:profile:show --capability=remux --web-workers=2 --database-slots=4 --ordinary-consumers=1 --copy-slots=2"
make exec cmd="php bin/console app:deployment:profile:show --hardware-accelerator=vaapi --hardware-device=/dev/dri/renderD128"
```

## Options

| Option | Description |
|--------|-------------|
| `--capability=NAME` | Media work the deployment runs: `transcode` or `remux` |
| `--web-workers=N` | Web worker processes, 1 to 16 |
| `--database-slots=N` | Database connection slots per web worker, 1 to 16 |
| `--ordinary-consumers=N` | Resident ordinary background consumers, 1 to 4 |
| `--copy-slots=N` | Concurrent stream copy jobs of a `remux` deployment, 1 to 8 |
| `--video-slots=N` | Concurrent video jobs of a `transcode` deployment; `0` disables video conversion and stream copy |
| `--audio-slots=N` | Concurrent audio jobs of a `transcode` deployment; `0` disables audio conversion and stream copy |
| `--hardware-accelerator=NAME` | Hardware encoder of a `transcode` deployment: `vaapi`, `qsv`, `nvenc` or `amf` |
| `--hardware-device=PATH` | Device the hardware encoder opens, such as `/dev/dri/renderD128`; `vaapi`, `qsv` and `amf` require one and `nvenc` takes none |
| `--json` | Print the profile, its fingerprint and its limits as JSON |

## Details

Without options, the command prints the profile this process runs, read from
`BAANDER_DEPLOYMENT_PROFILE`; an unset variable is the default profile,
`transcode` at the 16 GB starting point. Any option builds another profile and
leaves the environment unchanged.

Settings left out are inherited from the effective profile while the capability
stays the same. When `--capability` names the other capability, nothing is
inherited: process settings and transcode slots take the defaults, and a `remux`
profile requires `--copy-slots`. `--hardware-accelerator` names a new encoder, so
unset video and audio slots take the hardware defaults instead of the effective
counts: 12 video slots, measured on the reference GPU, against 8 for software
encoding, and 12 audio slots. `--hardware-device` requires
`--hardware-accelerator`. See
[Hardware acceleration](../transcoding.md#hardware-acceleration) for what the
hardware encoder changes.

The text output starts with the `BAANDER_DEPLOYMENT_PROFILE` line for web and the
source install's `.env.local`. Its JSON is also the worker manifest's
`deploymentProfile` object, including the `hardware` entry. A table lists the
profile fingerprint, the capability, the web workers with their database slots, the
ordinary consumers, the hardware encoder, the slots with their maximums, and these
derived limits:

| Limit | Meaning |
|-------|---------|
| Native commands | Commands the native daemon runs at once: one for media control, two per video or copy slot (encoder or copy, and helper) and one per audio slot |
| Native results | Results the daemon retains, running or awaiting release: twice the commands plus two |
| Native clients | Clients the daemon admits: the supervisor, media control and one per slot |
| Native daemon peers | Open connections the daemon accepts before further peers wait: the clients plus four |
| Control supervisor children | Processes the control unit's supervisor may own: the relay, scheduler, autoscaler, media manager, one media child per slot and the lease helper |
| Execution supervisor children | Processes the execution unit's supervisor may own at the profile's largest ordinary concurrency, with scheduled console execution enabled |

With `--json`, stdout holds one object: `profile` (the settings as
`BAANDER_DEPLOYMENT_PROFILE` carries them), `fingerprint`, and `limits`, which
lists the same figures as the table.

The video and audio slot maximums of a `transcode` profile come from the pooler,
which admits 500 client connections. The web workers, the worker roles, one
operator console command and the deployment controller hold a number that follows
the size. At the 16 GB starting point they hold 65, which leaves 435 for media jobs.
A video job opens 3 pooled connections and an audio job 2, so that profile accepts
at most 145 video slots or 217 audio slots, and both kinds together must fit the
435. A value beyond a bound is rejected with the bound it exceeds. A `remux`
profile prints no slot maximums and rejects video slots, audio slots and a
hardware encoder.

Web health compares this fingerprint with the one recorded by the active worker
boot; see [app:health:check](app-health-check.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The profile and its limits were printed |
| 2 | The options do not form a valid profile: an unknown capability, a value that is not an integer, a size setting outside its bound, slots beyond their maximum, copy slots for a `transcode` profile, a `remux` profile without copy slots or with video slots, audio slots or a hardware encoder, an unknown accelerator, a missing or unexpected device, a device without an accelerator, or a hardware encoder with zero video slots |
