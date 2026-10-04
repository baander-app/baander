import assert from 'node:assert/strict';
import test from 'node:test';
import { loadWasm } from './wasm-fixture.mjs';

function close(actual, expected, tolerance = 0.002) {
  assert.ok(Math.abs(actual - expected) <= tolerance,
    `expected ${expected}, received ${actual}`);
}

async function fixture(fftSize = 2048, sampleRate = 48000) {
  const api = await loadWasm('spectral_features');
  api.init_features(fftSize, sampleRate);
  return {
    api,
    compute(magnitudes) {
      const ptr = api.wasm_malloc(magnitudes.length);
      try {
        new Uint8Array(api.memory.buffer, ptr, magnitudes.length).set(magnitudes);
        api.compute_from_mag(ptr);
      } finally {
        api.wasm_free(ptr);
      }
    },
  };
}

test('single spectral bins retain their FFT frequencies, including the last bin', async () => {
  const { api, compute } = await fixture();
  for (const bin of [0, 1, 128, 512, 1023]) {
    const magnitudes = new Uint8Array(1024);
    magnitudes[bin] = 255;
    compute(magnitudes);
    close(api.get_centroid_hz(), bin * 48000 / 2048);
    close(api.get_rolloff_hz(0.85), bin * 48000 / 2048);
    assert.equal(api.get_peak_index(), bin);
  }
});

test('centroid preserves a weighted fractional bin', async () => {
  const { api, compute } = await fixture();
  const magnitudes = new Uint8Array(1024);
  magnitudes[10] = 255;
  magnitudes[11] = 85;
  compute(magnitudes);
  close(api.get_centroid_hz(), 10.25 * 48000 / 2048);
  assert.equal(api.get_peak_index(), 10);
});

test('rolloff uses cumulative magnitude and clamps the requested percentile', async () => {
  const { api, compute } = await fixture(64, 32000);
  const magnitudes = new Uint8Array(32);
  magnitudes[2] = 64;
  magnitudes[7] = 128;
  magnitudes[12] = 64;
  compute(magnitudes);
  for (const [percentile, bin] of [[-1, 0], [0.2, 2], [0.5, 7], [0.9, 12], [2, 12]]) {
    close(api.get_rolloff_hz(percentile), bin * 500);
  }
});

test('flux counts positive changes from the preceding frame', async () => {
  const { api, compute } = await fixture(64, 32000);
  const magnitudes = new Uint8Array(32);
  magnitudes[2] = 100;
  compute(magnitudes);
  close(api.get_flux(), 0);
  magnitudes[2] = 50;
  magnitudes[7] = 200;
  compute(magnitudes);
  close(api.get_flux(), 200 / 255, 1e-6);
  compute(magnitudes);
  close(api.get_flux(), 0);
  compute(new Uint8Array(32));
  close(api.get_flux(), 0);
});

test('flatness matches the geometric to arithmetic mean ratio', async () => {
  const { api, compute } = await fixture(64, 32000);
  compute(new Uint8Array(32).fill(128));
  close(api.get_flatness(), 1, 1e-6);
  const magnitudes = Uint8Array.from({ length: 32 }, (_, i) => i % 2 ? 255 : 64);
  compute(magnitudes);
  close(api.get_flatness(), Math.sqrt(64 * 255) / ((64 + 255) / 2), 1e-6);
});

test('log bands select bins using the FFT bin spacing', async () => {
  const { api, compute } = await fixture(64, 32000);
  const magnitudes = new Uint8Array(32);
  magnitudes[7] = 255;
  compute(magnitudes);
  const ptr = api.wasm_malloc(4);
  try {
    api.get_band_energies(ptr, 4);
    // Inclusive ranges: [0,1], [0,2], [1,7], [6,31].
    assert.deepEqual([...new Uint8Array(api.memory.buffer, ptr, 4)], [0, 0, 36, 10]);
  } finally {
    api.wasm_free(ptr);
  }
});

test('silence and reinitialization reset features and preceding-frame flux', async () => {
  const { api, compute } = await fixture(64, 32000);
  compute(new Uint8Array(32));
  close(api.get_centroid_hz(), 0);
  close(api.get_rolloff_hz(0.85), 0);
  close(api.get_flatness(), 0);
  close(api.get_flux(), 0);
  assert.equal(api.get_peak_index(), 0);
  compute(new Uint8Array(32).fill(255));
  api.init_features(128, 16000);
  close(api.get_centroid_hz(), 0);
  close(api.get_rolloff_hz(0.85), 0);
  close(api.get_flatness(), 0);
  close(api.get_flux(), 0);
  assert.equal(api.get_peak_index(), 0);
  const magnitudes = new Uint8Array(64);
  magnitudes[8] = 255;
  compute(magnitudes);
  close(api.get_centroid_hz(), 1000);
  close(api.get_flux(), 0);
});
