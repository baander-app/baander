# Equalizer Extensions Plan

## Problem summary

The equalizer is a basic 10-band parametric EQ with binary compression, fixed presets, and a flat JSONB payload synced to a single `audio_preferences` row per user. The brainstorm (see `docs/brainstorms/2026-05-11-equalizer-extensions.md`) identified 9 extensions: device profiles, parametric EQ mode, adjustable compressor, stereo width, crossfeed, loudness contour, spectrum overlay, per-band Q + schema v2, and A/B comparison. All must sync to backend, support effect chain reordering (per profile), and use a compact vertical stack processing UI.

## Relevant learnings

No prior solution docs found. Key architectural constraints from project rules:
- DDD state-object pattern for aggregate roots
- Port pattern for controllers (never inject repositories directly)
- JSONB for flexible payload, UUID v7 for PKs, TEXT not VARCHAR
- Frontend: Zustand stores with `persist` + `partialize`, `@/` imports, shadcn/ui, `AXIOS_INSTANCE`
- Audio graph: BiquadFilter array, DynamicsCompressorNode, GainNodes, WASM analysis pipeline

### Audio graph topology (critical for chain reconnection)

The current audio graph is **not** a simple linear chain. It has parallel branches:

```
source ──→ analyzer ──→ EQ filters ──→ compressor ──→ masterGain ──→ gain ──→ destination
   │                                                          ↑
   └──→ wasmSpectrumNode (parallel analysis, not in main chain)
              (when LUFS worklet active:)
         compressor ──→ audioWorkletNode ──→ masterGain
```

Key constraints for chain reconnection (Unit 9):
- `source → wasmSpectrumNode` is an independent parallel branch for WASM FFT. Must survive chain rebuild.
- The LUFS worklet (`audioWorkletNode`) inserts between compressor and masterGain when active. Chain rebuild must account for this.
- `source → analyzer` must always stay first in the main chain.
- `gain → destination` must always stay last.
- Every DSP method guards with `if (this.passiveMode) return`. New modules must follow this pattern.

### Store architecture decision

The eq-store will grow from ~12 fields to ~40+ fields. Rather than a god store, **split into focused stores** that cross-reference via `getState()`:

| Store | Fields |
|-------|--------|
| `eq-bands-store.ts` | bands, Q, peqPoints, eqMode, presets |
| `eq-processing-store.ts` | compressor params, stereo, crossfeed, loudness, chainOrder, masterGain, normalization |
| `eq-profiles-store.ts` | profiles, activeProfileId, device mappings |
| `eq-compare-store.ts` | compareSlotA/B, activeCompareSlot |

Each store gets its own `persist` config with appropriate `partialize` and `version` + `migrate` callbacks. The `persist` version bumps to 2 for `eq-bands-store` (bands shape change) and stays at 1 for new stores.

### Module bypass semantics

Toggling a module off does **not** disconnect its node (which would break the audio chain). Instead, each module defines a **passthrough state**:

| Module | Bypass state |
|--------|-------------|
| EQ | All filter gains → 0 dB |
| Compressor | Threshold → -50 dB, ratio → 1 |
| Stereo | Width → 1.0 (no processing) |
| Crossfeed | Disconnect cross-feed paths only, keep through-path |
| Loudness | Both shelf gains → 0 dB |
| Master Gain | No bypass (always active) |

### Volume → loudness subscription

The player store's `setVolume()` directly calls `processor.setVolume()`. The loudness contour needs to react to volume changes. Approach: **modify `AudioProcessor.setVolume()` to accept the volume percent** (0–100) and internally update loudness filter gains when loudness is enabled. This keeps the subscription logic inside the processor rather than adding a cross-store listener.

## Scope boundaries

**In scope:**
- Backend: new `eq_device_profiles` table + CRUD endpoints + migration
- Backend: extend `audio_preferences` payload schema to v2 (backward-compatible)
- Frontend: per-band Q control
- Frontend: adjustable compressor (threshold, ratio, knee, attack, release + gain reduction meter)
- Frontend: stereo width slider + mid/side solo
- Frontend: crossfeed DSP + headphone auto-detection
- Frontend: loudness contour (Fletcher-Munson via volume hook)
- Frontend: spectrum overlay on EQ graph
- Frontend: A/B comparison slots
- Frontend: parametric EQ mode (15-point draggable curve)
- Frontend: device profiles (CRUD, auto-switch, icon selection)
- Frontend: compact vertical stack processing UI with chain reorder
- Frontend: audio graph reconnection logic for user-adjustable chain order

**Out of scope:**
- Per-track/album EQ profiles
- Auto-EQ (profile matching)
- Spectrogram/waterfall display
- De-esser
- Noise gate
- Brick-wall limiter (can use compressor in limiter preset)
- Export/import of community EQ profiles
- Channel balance (stereo width at 0% effectively provides mono)

## Implementation units

### Unit 1: Backend — EQ Device Profiles Entity + Migration

**Goal:** Create the `eq_device_profiles` table and Doctrine entity supporting named, full-config EQ profiles per user.

**Files:**
- Create `src/UserPreference/Infrastructure/Doctrine/Entity/EqDeviceProfileEntity.php`
- Create `migrations/Version032_CreateEqDeviceProfilesTable.php`
- Create `src/UserPreference/Domain/Repository/EqDeviceProfileRepositoryInterface.php`
- Create `src/UserPreference/Infrastructure/Doctrine/Repository/EqDeviceProfileDoctrineRepository.php`

**Table schema:**
```sql
CREATE TABLE eq_device_profiles (
    id UUID PRIMARY KEY,
    user_id UUID NOT NULL REFERENCES users(id),
    name TEXT NOT NULL,
    icon TEXT NOT NULL DEFAULT 'custom',
    device_id TEXT DEFAULT NULL,
    payload JSONB NOT NULL DEFAULT '{}',
    is_default BOOLEAN NOT NULL DEFAULT false,
    sort_order INTEGER NOT NULL DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP(0) NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP(0) NOT NULL DEFAULT NOW(),
    UNIQUE(user_id, name)
);
```

**Patterns to follow:**
- Entity: `AudioPreferencesEntity.php` (UUID PK, JSONB payload, timestamps)
- Repository: `AudioPreferencesDoctrineRepository.php` (findByUserId, save)
- Migration: `Version031_AddLibraryScanStatus.php`

**Test scenarios:**
- Entity construction with UUID, userId, name, icon, payload, isDefault
- Repository save + findByUserId returns entity
- Repository findByUserId returns null for non-existent user

**Verification:**
```bash
make php CMD="./vendor/bin/phpunit tests/Unit/UserPreference/"
make php CMD="./vendor/bin/phpunit tests/Integration/UserPreference/"
```

**Dependencies:** None

---

### Unit 2: Backend — EQ Device Profiles Port + Controller + Wiring

**Goal:** CRUD API for device profiles: list, create, update, delete, activate. Port interface + adapter + controller + request DTOs.

**Files:**
- Create `src/UserPreference/Application/Port/EqDeviceProfilePortInterface.php`
- Create `src/UserPreference/Infrastructure/EqDeviceProfileAdapter.php`
- Create `src/UserPreference/Interface/Controller/EqDeviceProfileController.php`
- Create `src/UserPreference/Interface/Request/CreateEqDeviceProfileRequest.php`
- Create `src/UserPreference/Interface/Request/UpdateEqDeviceProfileRequest.php`
- Create `src/UserPreference/Interface/Resource/EqDeviceProfileResource.php`
- Modify `config/services.yaml` — add port + repository aliases
- Modify `config/routes.yaml` — add profile routes (if not auto-discovered)

**Patterns to follow:**
- Port: `AudioPreferencesPortInterface.php`
- Adapter: `AudioPreferencesAdapter.php` (history + versioning)
- Controller: `AudioPreferencesController.php` (SecurityUser extraction, OA annotations)
- Request: `SaveAudioPreferencesRequest.php` (Assert\Validation)
- Resource: `PlaylistResource.php` (static `from()`)

**Test scenarios:**
- Port: create profile for user, list profiles, delete profile, activate profile
- Controller: GET returns list, POST creates, PUT updates, DELETE removes, POST activate switches active
- Request validation: name required, icon enum validation, payload structure
- Active profile: activate updates audio_preferences.activeProfileId
- Default profile: cannot be deleted

**Verification:**
```bash
make php CMD="./vendor/bin/phpunit tests/Unit/UserPreference/"
make php CMD="./vendor/bin/phpunit tests/Integration/UserPreference/"
```

**Dependencies:** Unit 1

---

### Unit 3: Backend — Relax Audio Preferences Payload Validation

**Goal:** Update `SaveAudioPreferencesRequest` to accept schema v2 flexible payload (schemaVersion field, extended compressor/stereo/crossfeed/loudness/chainOrder/compareSlots/peqPoints). Existing v1 payloads continue to work.

**Files:**
- Modify `src/UserPreference/Interface/Request/SaveAudioPreferencesRequest.php` — replace strict `Assert\Collection` with version-aware validation
- Modify `src/UserPreference/Interface/Controller/AudioPreferencesController.php` — update OA schema docs

**Validation strategy:** Replace the strict `Assert\Collection` (which requires exact fields) with:
- `schemaVersion`: required, integer, `Assert\Range(min=1, max=2)`
- `payload`: required, `Assert\Type('array')`, minimum structure check (at least `bands` key present)
- Defer detailed field validation to frontend — backend is a JSONB passthrough with shallow type guards

This prevents corrupt payloads while keeping the backend agnostic to the exact v2 schema shape.

**Test scenarios:**
- v1 payload (no schemaVersion) still accepted
- v2 payload with all new fields accepted
- Payload with invalid schemaVersion rejected
- Missing payload field still rejected

**Verification:**
```bash
make php CMD="./vendor/bin/phpunit tests/Unit/UserPreference/Interface/Request/"
```

**Dependencies:** None (parallel with Units 1–2)

---

### Unit 4: Frontend — Store Split, Schema v2 Migration, Per-Band Q Control

**Goal:** Split the monolithic eq-store into focused stores. Migrate to schema v2 payload format with localStorage migration. Add per-band Q control in simple mode.

**Files:**
- Create `ui/web/src/features/equalizer/stores/eq-bands-store.ts` — bands, Q, peqPoints (empty for now), eqMode, presets. Persist version 2 with `migrate` callback that converts v1 flat `bands: number[]` → v2 `bands: {gain: number, q: number}[]`
- Create `ui/web/src/features/equalizer/stores/eq-processing-store.ts` — compressor params (defaults from current hardcoded values), stereo, crossfeed, loudness, chainOrder, masterGain, normalization. Persist version 1.
- Create `ui/web/src/features/equalizer/stores/eq-profiles-store.ts` — profiles array, activeProfileId. No persist (profiles live on backend, local cache only).
- Create `ui/web/src/features/equalizer/stores/eq-compare-store.ts` — compareSlotA/B, activeCompareSlot. Persist version 1.
- Delete `ui/web/src/features/equalizer/stores/eq-store.ts` — replaced by the four stores above
- Modify `ui/web/src/features/equalizer/stores/eq-handlers.ts` — update imports to new stores, map `SETTINGS_ACTIONS.APPLY_EQ` to correct store `setState` calls
- Modify `ui/web/src/features/player/services/audio-processor.ts` — refactor `updateEQBands(bands: number[])` → `updateEQBands(bands: BandConfig[])` where `BandConfig = { gain: number, q: number }`, apply both `filter.gain` and `filter.Q` via `setTargetAtTime`
- Modify `ui/web/src/features/settings/hooks/use-audio-preferences.ts` — v2 payload mapping, `toPayload` reads from all four stores, `fromPayload` dispatches to correct stores
- Modify `ui/web/src/features/settings/settings-actions.ts` — extend `SettingsApplyEqPayload` with Q, compressor object, new fields
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — per-band Q slider (range 0.1–10, default 0.7) beneath gain readout. Update all store selectors to use correct stores.
- Create `ui/web/tests/features/equalizer/stores/eq-bands-store-v2-migration.test.ts`
- Modify `ui/web/tests/features/equalizer/stores/eq-store-cycle.test.ts` — update imports to `eq-bands-store`
- Create `ui/web/tests/features/equalizer/stores/eq-processing-store.test.ts`

**Store persist `partialize` + `migrate`:**
- `eq-bands-store`: `partialize` includes `bands`, `peqPoints`, `eqMode`, `preset`. `migrate(persisted, version)`: if version < 2, convert `bands: number[]` → `bands: {gain, q: 0.7}[]`. Bump persist version to 2.
- `eq-processing-store`: `partialize` includes all compressor params, stereo params, crossfeed params, loudness, chainOrder, masterGain, normalization. Version 1 (new store, no migration needed).
- `eq-compare-store`: `partialize` includes `compareSlotA`, `compareSlotB`. `activeCompareSlot` is transient (resets to null on reload). Version 1.

**ReapplyEqState:** Export a `reapplyEqState()` from each store module that the processor can call after AudioContext resume:
- `eq-bands-store`: reapply bands + Q to filters
- `eq-processing-store`: reapply compressor params, stereo width, crossfeed, loudness gains, master gain, chain order
- Chain these in a single `reapplyAllEqState()` function

**Test scenarios:**
- v1 localStorage migration: flat `bands: [0,0,...]` → `{bands: [{gain:0, q:0.7}, ...]}`
- v2 payload loads directly with Q values
- setBandGain updates gain only; setBandQ updates Q only
- Q values persist to localStorage and sync to backend
- BiquadFilter Q updated via `filter.Q.setTargetAtTime(q, ...)`
- `reapplyAllEqState()` applies all four stores' state to processor
- Cycle preset still works (imports from eq-bands-store)
- Cross-store `getState()` reads work (e.g. processing store reads bands for sync)

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/stores/
```

**Dependencies:** Unit 3 (backend accepts v2 payload)

---

### Unit 5: Frontend — Adjustable Compressor Parameters

**Goal:** Replace binary compressor toggle with expandable compressor section exposing threshold, ratio, knee, attack, release + gain reduction meter.

**Files:**
- Modify `ui/web/src/features/equalizer/stores/eq-store.ts` — add `compressorThreshold`, `compressorRatio`, `compressorKnee`, `compressorAttack`, `compressorRelease`
- Modify `ui/web/src/features/player/services/audio-processor.ts` — expose setCompressorParams method, read `reduction` in getAnalysisData
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — expandable compressor row
- Create `ui/web/tests/features/equalizer/stores/eq-store-compressor.test.ts`

**Patterns to follow:**
- `setCompression(enabled)` currently sets threshold/ratio. Refactor to accept full params object
- DynamicsCompressorNode properties: threshold, ratio, knee, attack, release are all `AudioParam` — use `setTargetAtTime()`
- Gain reduction: `compressorNode.reduction` is a float, read per-frame

**Passive mode:** `setCompressorParams()` guards with `if (this.passiveMode) return`. In passive mode, compressor settings are stored in state but not applied to the audio graph. When transitioning out of passive mode, `reapplyAllEqState()` applies stored params.

**Bypass semantics:** When `compressionEnabled` is false, set `threshold=-50, ratio=1` (passthrough). Do not disconnect the compressor node.

**Test scenarios:**
- setCompressorParams applies all 5 parameters
- Compressor preset buttons set correct parameter combinations
- Gain reduction meter reads from processor (add `compressorReduction` to `AnalysisData`)
- Compressor bypass: threshold=-50, ratio=1 (passthrough, node stays in chain)
- Parameters persist and sync
- Passive mode: params stored but not applied to audio graph

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/stores/eq-store-compressor.test.ts
```

**Dependencies:** Unit 4 (store schema v2)

---

### Unit 6: Frontend — Stereo Width + Mid-Side Processing

**Goal:** Add stereo width control (0–200%) and mid/side solo modes.

**Files:**
- Modify `ui/web/src/features/equalizer/stores/eq-store.ts` — add `stereoWidth`, `stereoMode`
- Modify `ui/web/src/features/player/services/audio-processor.ts` — add stereo M/S processing nodes (splitter, merger, gain nodes), `setStereoWidth()` method, insert into audio graph
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — stereo width row in processing stack
- Create `ui/web/tests/features/equalizer/stores/eq-store-stereo.test.ts`

**DSP implementation:**
- `ChannelSplitterNode` → L/R channels
- M/S encode: mid = (L+R)/2, side = (L-R)/2
- Scale side by width factor
- M/S decode: L = mid + side×width, R = mid - side×width
- For solo: mute mid or mute side before re-encoding
- Insert point: configurable via chain order

**Passive mode:** `setStereoWidth()` guards with `if (this.passiveMode) return`. In passive mode, stereo width setting is stored in state but not applied.

**Bypass semantics:** When stereo module is disabled, set `width=1.0` (no processing). Do not disconnect the splitter/merger.

**Test scenarios:**
- Width 0 = mono (side fully attenuated)
- Width 100 = normal stereo (no processing)
- Width 200 = extra-wide (side amplified)
- Mid solo: only mid channel output
- Side solo: only side channel output
- Stereo mode normal: width slider active
- Passive mode: width stored but not applied
- Bypass: width set to 1.0, node stays in chain

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/stores/eq-store-stereo.test.ts
```

**Dependencies:** Unit 4

---

### Unit 7: Frontend — Loudness Contour

**Goal:** Add Fletcher-Munson loudness compensation tied to volume level.

**Files:**
- Modify `ui/web/src/features/equalizer/stores/eq-store.ts` — add `loudnessContourEnabled`
- Modify `ui/web/src/features/player/services/audio-processor.ts` — add two BiquadFilters (lowshelf 100Hz, highshelf 8kHz), `setLoudnessContour()` method, `updateLoudnessGains(volumePercent)` method
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — loudness toggle in processing stack
- Create `ui/web/tests/features/equalizer/stores/eq-store-loudness.test.ts`

**DSP:**
- `bassFilter`: BiquadFilter type='lowshelf', frequency=100Hz
- `trebleFilter`: BiquadFilter type='highshelf', frequency=8000Hz
- Gain computed: `r = 1 - volume/100`, bass = r×12dB, treble = r×6dB

**Volume subscription approach:** Modify `AudioProcessor.setVolume(volume: number)` to accept the volume percent (0–100). When loudness is enabled, compute and apply shelf filter gains inside `setVolume`. This keeps the coupling inside the processor rather than requiring a cross-store zustand subscription.

**Bypass semantics:** When loudness is disabled, set both shelf filter gains to 0dB. Do not disconnect the filters.

**Normalization vs gainNode conflict:** The current `applyVolumeNormalization()` writes to `this.gainNode.gain`, which is also used by `setVolume()`. When loudness is enabled and normalization is active, they fight over `gainNode`. Fix: move normalization to use `this.masterGainNode` instead of `this.gainNode`. `gainNode` is reserved for volume + mute only. This requires updating `applyVolumeNormalization()` in `audio-processor.ts`.

**Test scenarios:**
- At 100% volume: both gains = 0dB
- At 50% volume: bass = +6dB, treble = +3dB
- At 0% volume: bass = +12dB, treble = +6dB
- Toggle off: both gains set to 0dB
- Volume change updates filter gains when enabled

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/stores/eq-store-loudness.test.ts
```

**Dependencies:** Unit 4

---

### Unit 8: Frontend — Crossfeed + Headphone Auto-Detection

**Goal:** Add crossfeed DSP for headphone users with auto-detection.

**Files:**
- Modify `ui/web/src/features/equalizer/stores/eq-store.ts` — add `crossfeedEnabled`, `crossfeedAmount`, `crossfeedPreset`
- Modify `ui/web/src/features/player/services/audio-processor.ts` — add crossfeed nodes (2× DelayNode + BiquadFilter lowpass + GainNode), `setCrossfeed()` method
- Create `ui/web/src/features/equalizer/hooks/use-headphone-detection.ts` — device enumeration + label matching + remember mapping
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — crossfeed row in processing stack
- Create `ui/web/tests/features/equalizer/hooks/use-headphone-detection.test.ts`

**DSP:**
- Per cross-feed path: `source → delay → lowpass → gain → opposite channel`
- Presets: Light (0.2ms/900Hz/-10dB), Normal (0.3ms/700Hz/-6dB), Heavy (0.5ms/500Hz/-3dB)
- **Shares the ChannelSplitter/Merger pair with stereo width** (Unit 6). Crossfeed is processed within the same M/S stage: after width scaling and before M/S decode, apply crossfeed delay+lowpass+gain to the side channel. This avoids phase artifacts from two sequential splitter/merger pairs and saves audio nodes.

**Passive mode:** `setCrossfeed()` guards with `if (this.passiveMode) return`.

**Bypass semantics:** When crossfeed is disabled, disconnect only the cross-feed paths (delay→lowpass→gain), keep the through-path intact.

**Auto-detection:**
- `navigator.mediaDevices.enumerateDevices()` on init + `devicechange` event
- Match label against: headphone, headset, airpod, earbuds, wh-1000, etc.
- First detection: prompt + "Remember for this device" checkbox
- Store `{ deviceId → crossfeedEnabled }` in profile payload

**Test scenarios:**
- Crossfeed enabled: delay + lowpass + gain nodes connected
- Crossfeed disabled: nodes disconnected, passthrough
- Preset switch updates delay/cutoff/gain
- Headphone detection: known label triggers callback
- Non-headphone label: no auto-enable
- Empty labels (no permission): no auto-enable, tooltip shown
- Remember mapping persisted

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/hooks/use-headphone-detection.test.ts
```

**Dependencies:** Unit 4

---

### Unit 9: Frontend — Audio Graph Chain Reconnection

**Goal:** Make the audio processing chain dynamically re-orderable. Fade out, disconnect, reconnect in new order, fade in.

**Files:**
- Modify `ui/web/src/features/equalizer/stores/eq-processing-store.ts` — `chainOrder: ProcessingModule[]` with default `['eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'masterGain']`
- Modify `ui/web/src/features/player/services/audio-processor.ts` — add `rebuildChain(order: ProcessingModule[])` method
- Add `chainOrderMerge(stored: ProcessingModule[], available: ProcessingModule[]): ProcessingModule[]` utility — on load, merges stored order with any new modules not in the stored list (appends new modules at end)

**Processing modules (each mapped to node(s)):**
- `eq` → BiquadFilter array (connected sequentially)
- `compressor` → DynamicsCompressorNode
- `stereo` → ChannelSplitter/Merger pair with M/S processing (includes crossfeed, per Unit 8)
- `crossfeed` → no separate node — processed inside the stereo M/S stage
- `loudness` → two shelf filters
- `masterGain` → masterGainNode
- Normalization excluded (modifies masterGainNode, not a chain node)

**Chain rebuild procedure:**
1. Fade `masterGainNode.gain` to 0 over 10ms via `setTargetAtTime(0, ctx.currentTime, 0.005)`
2. Wait 15ms (via `setTimeout`)
3. Disconnect all inter-module connections (not source→analyzer, not gain→destination)
4. Reconnect modules in new order: `analyzer → [modules in order] → gainNode`
5. Preserve parallel branches: `source → wasmSpectrumNode` untouched; LUFS worklet reinserted after compressor if active
6. Reapply all module parameters (filter gains, compressor settings, stereo width, etc.)
7. Fade `masterGainNode.gain` back to 1 over 10ms

**Chain order migration:** When loading a stored `chainOrder` that doesn't include a module present in the default list (e.g. a new module added in a future version), append the missing module at the end before the last module. If `masterGain` is missing, always ensure it's last.

**Test scenarios:**
- Default order connects correctly
- Custom order: all permutations connect correctly
- Rebuild preserves all parameter values
- Rebuild during playback: fade-out/fade-in prevents audible click
- Source → wasmSpectrumNode branch survives rebuild
- LUFS worklet reinserted after compressor when active
- Chain order migration: stored `['eq', 'compressor', 'masterGain']` + available `['eq', 'compressor', 'stereo', 'loudness', 'masterGain']` → `['eq', 'compressor', 'stereo', 'loudness', 'masterGain']` (new modules appended)
- `masterGain` always last even if stored order places it elsewhere

**Verification:**
```bash
cd ui/web && yarn test features/player/services/audio-processor-chain.test.ts
```

**Dependencies:** Units 5, 6, 7, 8 (all processing modules must exist)

---

### Unit 10: Frontend — Processing UI: Compact Vertical Stack + Chain Reorder

**Goal:** Replace the current flat Processing card with a compact vertical stack of expandable rows + chain reorder popover.

**Files:**
- Create `ui/web/src/features/equalizer/components/ProcessingStack.tsx` — vertical stack of processing module rows
- Create `ui/web/src/features/equalizer/components/ProcessingModuleRow.tsx` — single expandable row (toggle + primary param + expand)
- Create `ui/web/src/features/equalizer/components/ChainReorderPopover.tsx` — drag-to-reorder list
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — replace Processing card with ProcessingStack
- Create `ui/web/tests/features/equalizer/components/ProcessingStack.test.tsx`

**UX:**
- Each row: colored dot (active=primary), module name, primary parameter inline, on/off toggle
- Click row → expands to show all parameters
- Separator between chain modules and normalization (outside chain)
- Chain reorder: drag grip handles, "Reset to default order" button
- Master Gain row: always visible (not togglable), shown at bottom

**Test scenarios:**
- All 6 chain modules render in correct order
- Normalization renders below separator
- Toggle disables module (dims row, disconnects node)
- Expand row shows all parameters
- Chain reorder: drag item 2 to position 0, order updates
- Reset restores default order
- Active profile's chain order loads on profile switch

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/components/ProcessingStack.test.tsx
```

**Dependencies:** Units 5, 6, 7, 8, 9

---

### Unit 11: Frontend — Spectrum Overlay on EQ Graph

**Goal:** Render live frequency spectrum behind EQ controls.

**Files:**
- Create `ui/web/src/features/equalizer/components/SpectrumOverlay.tsx` — canvas/SVG layer for frequency data
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — integrate overlay behind simple-mode sliders
- Create `ui/web/tests/features/equalizer/components/SpectrumOverlay.test.tsx`

**Simple mode**: sample frequencyData at each band's center frequency, render semi-transparent bar behind each slider. Color: `primary/30` to `primary/60` gradient.

**PEQ mode** (later): full frequency response filled area on PEQ graph canvas.

**Test scenarios:**
- Overlay renders with frequency data
- Overlay clears when not playing
- Bar heights proportional to frequency data values
- Overlay opacity does not obscure slider controls

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/components/SpectrumOverlay.test.tsx
```

**Dependencies:** Unit 4 (schema v2)

---

### Unit 12: Frontend — A/B Comparison

**Goal:** Two save slots with instant toggle for comparing EQ states.

**Files:**
- Modify `ui/web/src/features/equalizer/stores/eq-store.ts` — add `compareSlotA`, `compareSlotB`, `activeCompareSlot`, `saveToSlot()`, `loadSlot()`, `toggleCompare()`, `exitCompare()`
- Create `ui/web/src/features/equalizer/components/CompareToggle.tsx` — A/B button pair
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — integrate CompareToggle in EQ card header
- Create `ui/web/tests/features/equalizer/stores/eq-store-compare.test.ts`

**Test scenarios:**
- Save to slot A: captures full state (bands, Q, compressor, stereo, crossfeed, loudness, chain order)
- Save to slot B: independent of A
- Toggle A→B: instant swap, applies to audio graph immediately
- Toggle B→A: instant swap back
- Exit compare: returns to free-editing with last active slot's state
- Free-edit while in compare mode: changes apply to active slot only
- Slots persisted in profile payload

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/stores/eq-store-compare.test.ts
```

**Dependencies:** Unit 4

---

### Unit 13: Frontend — Parametric EQ Mode

**Goal:** Draggable frequency-response curve with up to 15 control points (frequency/gain/Q/type). Toggles with simple mode.

**Files:**
- Create `ui/web/src/features/equalizer/components/PeqGraph.tsx` — canvas PEQ graph with native pointer event drag handling (no external drag library — @dnd-kit is for sortable lists, not free-form 2D canvas)
- Create `ui/web/src/features/equalizer/components/PeqPointPopover.tsx` — selected point parameter editor (frequency/gain/Q/type inputs)
- Create `ui/web/src/features/equalizer/components/PeqPointList.tsx` — accessible fallback list view of PEQ points with standard inputs for screen readers
- Modify `ui/web/src/features/equalizer/stores/eq-bands-store.ts` — add `eqMode`, `peqPoints`, `addPeqPoint()`, `removePeqPoint()`, `updatePeqPoint()`, `setEqMode()`
- Modify `ui/web/src/features/player/services/audio-processor.ts` — expand filter pool to 15, map PEQ points to filters, `updatePeqFilters()` method
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — mode toggle, conditionally render PeqGraph or simple sliders
- Create `ui/web/tests/features/equalizer/components/PeqGraph.test.tsx`
- Create `ui/web/tests/features/equalizer/stores/eq-bands-store-peq.test.ts`

**PEQ curve rendering:**
- Use `getFrequencyResponse()` on each active BiquadFilter to compute the summed magnitude response
- Requires `Float32Array` frequency inputs (logarithmically spaced, 20Hz–20kHz, ~200 points)
- Run in a `requestAnimationFrame` loop (separate from the 25fps spectrum data polling) for smooth 60fps curve updates during drag
- Render on a `<canvas>` element with the summed curve as a filled area

**DSP:**
- 15 BiquadFilters in pool. Simple mode: first 10 at fixed freqs with Q. PEQ mode: all 15 assigned to points.
- Unused filters: gain=0 (passthrough)
- `getFrequencyResponse()` on each filter → sum for curve rendering

**Mode switch:**
- Simple → PEQ: create 10 points at fixed frequencies with stored gain + Q
- PEQ → Simple: sample the summed PEQ curve at 10 fixed frequencies, set as simple band gains

**Test scenarios:**
- Toggle simple→peq: 10 points created at fixed freqs with correct gain/Q
- Toggle peq→simple: bands sampled from PEQ curve
- Add point: new BiquadFilter allocated, type peaking, Q default 1.4
- Remove point: filter gain set to 0
- Drag point: frequency + gain update in real-time
- Shift-drag: Q updates
- Max 15 points: add blocked after 15
- Min 1 point: cannot delete last point
- Spectrum overlay renders behind PEQ curve

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/components/PeqGraph.test.tsx features/equalizer/stores/eq-store-peq.test.ts
```

**Dependencies:** Units 4, 11 (spectrum overlay)

---

### Unit 14: Frontend — Device Profiles UI

**Goal:** Profile selector, CRUD UI, icon selection, auto-switch on device change.

**Files:**
- Create `ui/web/src/features/equalizer/components/ProfileSelector.tsx` — dropdown with drag-to-reorder profiles (using existing `@dnd-kit/sortable`), icon + name display
- Create `ui/web/src/features/equalizer/components/ProfileIcon.tsx` — icon rendering for profile types (lucide-react icons: Headphones, Speaker, AudioLines, Wifi, Car, Tv, Monitor, Settings2)
- Create `ui/web/src/features/equalizer/components/CreateProfileDialog.tsx` — name + icon picker
- Create `ui/web/src/features/equalizer/api/eq-profiles-api.ts` — API calls for profile CRUD (`AXIOS_INSTANCE`)
- Modify `ui/web/src/features/equalizer/stores/eq-profiles-store.ts` — add `profiles`, `activeProfileId`, profile CRUD actions, sort order actions
- Modify `ui/web/src/features/equalizer/components/EqualizerPanel.tsx` — integrate ProfileSelector at top
- Modify `ui/web/src/features/equalizer/hooks/use-headphone-detection.ts` — trigger profile auto-switch on device change
- Create `ui/web/tests/features/equalizer/stores/eq-profiles-store.test.ts`
- Create `ui/web/tests/features/equalizer/components/ProfileSelector.test.tsx`

**Default profile creation for existing users:** On first load after upgrade, the bootstrap logic detects that no profiles exist but `audio_preferences` has a v1 payload. It calls `POST /api/user/eq-profiles` with `isDefault: true`, using the migrated v2 payload as the profile's initial state. This happens client-side during bootstrap, not in a SQL migration.

**Test scenarios:**
- Load profiles from API on mount
- Default profile always exists, cannot be deleted
- Create profile: captures current full state (reads from all four stores), assigns name + icon
- Switch profile: applies stored state to all four stores + audio graph via `reapplyAllEqState()`
- Delete profile: removes from list, switches to default
- Auto-switch: devicechange triggers profile lookup by deviceId
- Active profile indicator shows name + icon
- Changes auto-save to active profile (debounced, reads from all stores)
- Profile sort order: drag-to-reorder updates `sort_order` via API
- Existing user migration: v1 payload auto-creates default profile on first load

**Verification:**
```bash
cd ui/web && yarn test features/equalizer/stores/eq-store-profiles.test.ts features/equalizer/components/ProfileSelector.test.tsx
```

**Dependencies:** Units 2 (backend CRUD), 4, 5, 6, 7, 8, 9, 12, 13

---

### Unit 15: Integration — End-to-End Profile Sync + State Hydration

**Goal:** Wire everything together: profile bootstrapping, v1→v2 migration, cross-device sync, conflict resolution for profiles.

**Files:**
- Modify `ui/web/src/features/settings/hooks/use-preference-bootstrap.tsx` — bootstrap EQ from active profile on login
- Modify `ui/web/src/features/settings/hooks/use-audio-preferences.ts` — sync active profile pointer
- Create `ui/web/tests/features/settings/hooks/use-eq-profile-bootstrap.test.ts`

**Test scenarios:**
- Fresh user: default profile created with factory defaults via API
- Existing v1 user: `audio_preferences` payload migrated to v2, default profile created client-side with migrated payload
- Login: active profile loaded, full EQ state applied to audio graph via `reapplyAllEqState()`
- Profile switch: push new `activeProfileId` to preferences endpoint, debounce 500ms
- Conflict resolution: server version wins → re-fetch profile + apply to all four stores
- Offline: localStorage holds store state, sync on reconnect
- Store version migration: eq-bands-store `migrate` callback handles v1→v2 band shape change

**Verification:**
```bash
cd ui/web && yarn test features/settings/hooks/use-eq-profile-bootstrap.test.ts
```

**Dependencies:** All previous units

---

## Verification strategy

### Per-unit
Each unit has specific test commands in its verification section.

### Integration
```bash
# Backend: full test suite
make php CMD="./vendor/bin/paratest --processes auto --tmp-dir var"

# Frontend: full test suite
cd ui/web && yarn test

# E2E: equalizer smoke test
cd ui/web && yarn test tests/e2e/equalizer/
```

### Manual smoke test
1. Open app → Settings → Audio → verify default profile exists
2. Create "Headphones" profile → verify all processing modules render in vertical stack
3. Adjust compressor parameters → verify gain reduction meter animates
4. Toggle stereo width → verify mid/side processing
5. Enable crossfeed → verify preset switching
6. Enable loudness → lower volume → verify bass/treble boost
7. Switch to PEQ mode → add points → drag → verify filter response
8. Save A/B slots → toggle → verify instant swap
9. Reorder chain → verify audio path changes
10. Switch to "Speakers" profile → verify crossfeed auto-disables
11. Refresh page → verify profile + all settings restored
