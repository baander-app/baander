# Dynamics meter

`init_meters(rms_window_ms, release_ms, sample_rate)` clears both channels and
configures a rectangular RMS window and a sample-peak envelope. The RMS window
contains `round(rms_window_ms * sample_rate / 1000)` frames, clamped to 1–384000
frames to bound memory use. A nonpositive sample rate selects 48000 Hz.

RMS is `sqrt(sum(sample²) / window_frames)` over the most recent window.
Before a full window has arrived, missing samples count as zeros. Silence clears
the RMS once the last nonzero sample leaves the window. It has no additional
attack or release smoothing.

The peak envelope captures every sample immediately. Each subsequent frame sets
it to `max(abs(sample), previous_peak * exp(-1000 / (sample_rate * release_ms)))`.
Thus an isolated peak falls by a factor of `exp(-1)` after `release_ms` of audio.
A nonpositive release sets the envelope to the current sample magnitude. Peaks
are sampled values; this module does not estimate intersample peaks.

The `get_crest_*` exports return `20 * log10(peak_envelope / window_rms)`, or zero
when either value is zero. This is an envelope ratio using different histories,
so it can vary with signal phase and can be negative with a short release.
It is not the crest factor of a shared finite window.

`process_frames` consumes interleaved float32 samples, duplicates mono into both
channels, and ignores channels beyond the first two. State advances only when
samples are processed; splitting a buffer into calls does not change results.
`reset_meters` clears RMS history and the peak envelopes while preserving settings.
Call initialization before processing. Input samples and time settings must be
finite. The module makes no standardized meter compliance claim.

The WASM and JavaScript wrapper expose `malloc` and `free` for caller-owned input
buffers. Allocate `frames * channels * 4` bytes, write samples through a
`Float32Array` view of `memory.buffer`, and free the buffer after processing.
The module keeps no caller buffer pointers and allocates its RMS history only
during initialization. Do not use an arbitrary fixed memory offset: it can
overwrite the module's heap or RMS history.
