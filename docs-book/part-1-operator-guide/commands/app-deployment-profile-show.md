# app:deployment:profile:show

Print a deployment profile and the limits Baander derives from its media slots. A
source install uses it to get the web environment values and the worker manifest's
`deploymentProfile` object without writing the JSON by hand.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

```bash
make exec cmd="php bin/console app:deployment:profile:show"
make exec cmd="php bin/console app:deployment:profile:show --identifier=full --video-slots=4 --audio-slots=12"
make exec cmd="php bin/console app:deployment:profile:show --identifier=full --hardware-accelerator=vaapi --hardware-device=/dev/dri/renderD128"
```

## Options

| Option | Description |
|--------|-------------|
| `--identifier=PROFILE` | Build the `full` or `household` profile instead of showing the effective one |
| `--video-slots=N` | Concurrent video jobs of the `full` profile; `0` disables video conversion and stream copy |
| `--audio-slots=N` | Concurrent audio jobs of the `full` profile; `0` disables audio conversion and stream copy |
| `--hardware-accelerator=NAME` | Hardware encoder of the `full` profile: `vaapi`, `qsv`, `nvenc` or `amf` |
| `--hardware-device=PATH` | Device the hardware encoder opens, such as `/dev/dri/renderD128`; `vaapi`, `qsv` and `amf` require one and `nvenc` takes none |
| `--json` | Print the profile, its fingerprint and its limits as JSON |

## Details

Without `--identifier`, a slot option or a hardware option, the command prints the
profile this process runs, read from `BAANDER_DEPLOYMENT_PROFILE` and
`BAANDER_DEPLOYMENT_PROFILE_JSON`. Those options build another profile from the
options alone and leave the environment unchanged; the effective profile's hardware
encoder is not carried over. An option without `--identifier` builds the effective
profile's kind. An unset slot option takes the default: 8 video and 12 audio jobs.
With a hardware encoder, unset video slots take a separate measured default instead:
12 on the reference GPU. See
[Hardware acceleration](../transcoding.md#hardware-acceleration) for what the
hardware encoder changes.

The text output starts with the two environment lines for web and the source
install's `.env.local`. The JSON in `BAANDER_DEPLOYMENT_PROFILE_JSON` is also the
worker manifest's `deploymentProfile` object, including its `hardware` entry. A table
lists the profile fingerprint, the hardware encoder, the slots with their maximums,
and these derived limits:

| Limit | Meaning |
|-------|---------|
| Native commands | Commands the native daemon runs at once: one for media control, two per video slot (encoder and helper) and one per audio slot |
| Native results | Results the daemon retains, running or awaiting release: twice the commands plus two |
| Native clients | Clients the daemon admits: the supervisor, media control and one per slot |
| Native daemon peers | Open connections the daemon accepts before further peers wait: the clients plus four |
| Control supervisor children | Processes the control unit's supervisor may own: the relay, scheduler, autoscaler, media manager, one media child per slot and the lease helper |
| Execution supervisor children | Processes the execution unit's supervisor may own at the profile's largest ordinary concurrency, with scheduled console execution enabled |

With `--json`, stdout holds one object: `profile` (the settings as
`BAANDER_DEPLOYMENT_PROFILE_JSON` carries them), `fingerprint`, and `limits`, which
lists the same figures as the table.

The slot maximums come from the pooler, which admits 500 client connections. In
the `full` profile, the web workers, the worker roles, one operator console command
and the deployment controller hold 65 of them, which leaves 435 for media jobs. A
video job opens 3 pooled connections and an audio job 2, so the profile accepts at
most 145 video slots or 217 audio slots, and both kinds together must fit the 435.
A value beyond a maximum is rejected with the bound it exceeds. The `household`
profile runs two fixed shared copy slots and rejects slot options.

Web health compares this fingerprint with the one recorded by the active worker
boot; see [app:health:check](app-health-check.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The profile and its limits were printed |
| 2 | The options do not form a valid profile: an unknown profile, a slot value that is not an integer, slots beyond their maximum, slots or a hardware encoder for the `household` profile, an unknown accelerator, a missing or unexpected device, or a hardware encoder with zero video slots |
