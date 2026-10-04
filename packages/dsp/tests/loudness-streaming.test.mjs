import assert from 'node:assert/strict';
import test from 'node:test';
import { loadWasm } from './wasm-fixture.mjs';

function close(actual, expected, tolerance = 0.003) {
  assert.ok(Math.abs(actual - expected) <= tolerance,
    `expected ${expected}, received ${actual}`);
}

async function fixture(sampleRate, oversample = 1, maxFrames = 8192) {
  const api = await loadWasm('loudness_r128');
  api.init_loudness(sampleRate, oversample);
  const ptr = api.malloc(maxFrames * 2 * Float32Array.BYTES_PER_ELEMENT);
  return {
    api,
    feed(frames, signal = () => 0, offset = 0) {
      const buffer = new Float32Array(api.memory.buffer, ptr, frames * 2);
      for (let i = 0; i < frames; i++) {
        buffer[i * 2] = signal(offset + i, 0);
        buffer[i * 2 + 1] = signal(offset + i, 1);
      }
      api.process_frames(ptr, frames, 2);
    },
    readings() {
      return [api.get_lufs_momentary(), api.get_lufs_shortterm(),
        api.get_lufs_integrated(), api.get_lra()];
    },
    dispose() { api.free(ptr); },
  };
}

function signal(sampleRate) {
  return (frame, channel) => {
    const t = frame / sampleRate;
    const amplitude = t < 1 ? 0.15 : t < 2 ? 0.035 : t < 3 ? 0.3 : 0.1;
    return amplitude * Math.sin(2 * Math.PI * (channel ? 503 : 997) * t);
  };
}

function stream(meter, frames, chunkSize, generate) {
  for (let offset = 0; offset < frames; offset += chunkSize) {
    meter.feed(Math.min(chunkSize, frames - offset), generate, offset);
  }
}

// Recorded from the pre-optimization WASM with this signal and 128-frame feeds.
const baseline = {
  44100: [-23.123756408691406, -17.841062545776367, -18.817333221435547, 18.660449981689453],
  48000: [-23.123327255249023, -17.840614318847656, -18.816884994506836, 18.660449981689453],
};

for (const sampleRate of [44100, 48000]) {
  test(`streaming loudness preserves baseline and chunk boundaries at ${sampleRate} Hz`, async () => {
    const quantum = await fixture(sampleRate);
    const larger = await fixture(sampleRate);
    try {
      stream(quantum, sampleRate * 4, 128, signal(sampleRate));
      stream(larger, sampleRate * 4, 8192, signal(sampleRate));
      quantum.readings().forEach((value, i) => {
        close(value, baseline[sampleRate][i]);
        close(value, larger.readings()[i], 0.00001);
      });
    } finally { quantum.dispose(); larger.dispose(); }
  });

  test(`silence clears windows, long history stays bounded, and reset clears outputs at ${sampleRate} Hz`, async () => {
    const meter = await fixture(sampleRate);
    try {
      stream(meter, sampleRate * 4, 128, signal(sampleRate));
      stream(meter, sampleRate * 4, 128);
      close(meter.api.get_lufs_momentary(), -120.691, 0.0001);
      close(meter.api.get_lufs_shortterm(), -120.691, 0.0001);
      // More than 3000 history blocks must evict the earlier audible material.
      stream(meter, sampleRate * 302, 128);
      assert.equal(meter.api.get_lufs_integrated(), -70);
      close(meter.api.get_lra(), 0, 0.0001);
      meter.api.reset_loudness();
      assert.deepEqual(meter.readings(), [-70, -70, -70, 0]);
      assert.equal(meter.api.get_true_peak_dbfs(), -90);
      stream(meter, sampleRate * 4, 128, signal(sampleRate));
      meter.readings().forEach((value, i) => close(value, baseline[sampleRate][i]));
    } finally { meter.dispose(); }
  });
}

for (const oversample of [1, 2, 4]) {
  test(`peak estimator includes singleton and final samples at ${oversample}x`, async () => {
    const meter = await fixture(48000, oversample);
    try {
      meter.feed(1, (_, channel) => channel ? -0.75 : 0.25);
      close(meter.api.get_true_peak_dbfs(), 20 * Math.log10(0.75), 0.00001);
      meter.feed(128, (frame, channel) => frame === 127 && channel === 1 ? -0.5 : 0);
      close(meter.api.get_true_peak_dbfs(), 20 * Math.log10(0.5), 0.00001);
    } finally { meter.dispose(); }
  });
}
