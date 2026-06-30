# Equalizer Extensions Brainstorm

Date: 2026-05-11

## Current State

- **Frontend**: 10-band BiquadFilter EQ (31.5Hz–16KHz), fixed presets (Flat/Rock/Pop/Jazz/Classical/Bass/Treble/Vocal/Loudness), binary compressor toggle, master gain (-12/+12dB), LUFS normalization, spectrum/meters/phase visualizers
- **Backend**: `UserPreference` bounded context — `AudioPreferencesPortInterface` with JSONB payload, versioned save, history + rollback via `/api/user/audio-preferences/`
- **Sync**: `usePreferenceSync` + `useAudioPreferences` hooks debounce (500ms) to backend with conflict resolution
- **AudioProcessor**: BiquadFilter array, DynamicsCompressorNode, GainNodes, WASM spectral analysis, AudioWorklet for LUFS, Web Worker for FFT

## Extensions

### 1. Device Profiles

Named profiles storing full EQ config (e.g. "Sony WH-1000XM5", "Living Room", "Car"). Not per-track — per output device.

- New `eq_device_profiles` table: id (UUID), user_id, name, icon, device_id (optional), payload (JSONB), is_default, sort_order, version, timestamps
- CRUD: `GET/POST/PUT/DELETE /api/user/eq-profiles`, `POST /api/user/eq-profiles/{id}/activate`
- Existing `audio_preferences` stores `activeProfileId` as pointer
- Default profile auto-created from existing `audio_preferences` payload on migration
- Auto-switch: on `devicechange` event, match audio output device to profile's `deviceId`
- Icons: headphones, speakers, hifi-speaker, wireless-speaker, car, tv, monitor, custom

### 2. Parametric EQ Mode

Draggable frequency-response curve with up to 15 control points (frequency/gain/Q/type).

- Toggle: Simple (current 10-band) ↔ Parametric
- Pool of 15 BiquadFilters — simple mode uses 10 at fixed freqs, PEQ distributes dynamically
- PEQ graph: log X (20Hz–20kHz), linear Y (±24dB), spectrum overlay behind curve
- `getFrequencyResponse()` computes summed curve for rendering
- Mode switch converts between formats

### 3. Adjustable Compressor

Expose all DynamicsCompressorNode params: threshold (-60 to 0dB), ratio (1–20), knee (0–40dB), attack (0–1000ms), release (0–1000ms).

- Gain reduction meter from `compressorNode.reduction`
- Presets: Gentle, Moderate, Heavy, Limiter

### 4. Stereo Width / Mid-Side

Width slider 0% (mono) → 200% (extra-wide). Mid/Side solo modes.

- ChannelSplitter → M/S encode → width scaling → M/S decode → ChannelMerger
- Stereo correlation indicator

### 5. Crossfeed

Simulate speaker listening on headphones via delay + lowpass + gain cross-feed paths.

- Presets: Light (0.2ms/900Hz/-10dB), Normal (0.3ms/700Hz/-6dB), Heavy (0.5ms/500Hz/-3dB)
- Auto-enable on headphone detection: enumerate devices, match label, prompt + remember
- `deviceId → crossfeedEnabled` mapping stored in profile

### 6. Loudness Contour

Fletcher-Munson compensation: at low volumes boost bass (+12dB) and treble (+6dB) via two shelf filters.

- Gain derived from master volume: `r = 1 - volume/100`, bass = r×12, treble = r×6
- Subscribes to player store volume changes

### 7. Spectrum Overlay

Render `frequencyData` behind EQ controls: bars behind simple-mode sliders, filled area behind PEQ curve.

- Data already computed at 25fps — rendering only, no additional DSP

### 8. Per-Band Q + Backend Schema v2

- Per-band Q control (0.1–10, default 0.7) in simple mode
- Versioned JSONB payload with `schemaVersion: 2` supporting all new modules
- Migration: v1 → v2 auto-upgrade on frontend load
- Backend validation relaxes to accept flexible payload

### 9. A/B Comparison

Two save slots (A/B), instant toggle, exits to free-editing mode.

- `compareSlotA/B: EqSnapshot | null`, `activeCompareSlot: 'a' | 'b' | null`
- Persisted in profile payload

## Decisions

| Decision | Resolution |
|----------|-----------|
| Profile scope | Device profiles (full EQ config per named device) |
| EQ modes | Simple + Parametric coexist, user toggles |
| Chain order | User-adjustable, persisted per profile |
| Processing UI | Compact vertical stack, expandable rows |
| Crossfeed auto-enable | Yes, on headphone detection |
| Normalization in chain | Separate toggle, not in draggable chain |
| PEQ max points | 15 |
| Device icons | headphones, speakers, hifi-speaker, wireless-speaker, car, tv, monitor, custom |
| Chain order scope | Per profile |
