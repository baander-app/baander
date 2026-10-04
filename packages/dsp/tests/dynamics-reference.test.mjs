import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadWasm } from './wasm-fixture.mjs';
import { loadDynamics } from '../dynamics_meter/dynamics_meter.js';

function close(actual, expected, tolerance = 2e-6) {
  assert.ok(Math.abs(actual - expected) <= tolerance,
    `expected ${expected}, received ${actual}`);
}

async function fixture(sampleRate, windowMs = 100, releaseMs = 100) {
  const api = await loadWasm('dynamics_meter');
  api.init_meters(windowMs, releaseMs, sampleRate);
  return {
    api,
    process(samples, channels = 1) {
      const ptr = api.malloc(samples.length * Float32Array.BYTES_PER_ELEMENT);
      assert.ok(ptr > 0, 'WASM buffer allocation succeeded');
      try {
        new Float32Array(api.memory.buffer, ptr, samples.length).set(samples);
        api.process_frames(ptr, samples.length / channels, channels);
      } finally {
        api.free(ptr);
      }
    },
  };
}

function readings(api) {
  return [api.get_rms_left(), api.get_rms_right(), api.get_peak_left(),
    api.get_peak_right(), api.get_crest_left(), api.get_crest_right()];
}

test('JavaScript wrapper exposes safe allocator and processes actual WASM', async () => {
  const directory = process.env.DSP_WASM_DIR ?? fileURLToPath(new URL('../../../public/dsp/', import.meta.url));
  const bytes = await readFile(resolve(directory, 'dynamics_meter.wasm'));
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => ({ arrayBuffer: async () => bytes });
  let api;
  try {
    api = await loadDynamics('https://audio.baander.app/dynamics_meter.wasm');
  } finally {
    globalThis.fetch = originalFetch;
  }
  api.init(0, 100, 48000);
  const ptr = api.malloc(4);
  try {
    new Float32Array(api.memory.buffer, ptr, 1)[0] = -0.75;
    api.process(ptr, 1, 1);
    close(api.rmsL(), 0.75);
    close(api.peakL(), 0.75);
  } finally {
    api.free(ptr);
  }
});

for (const sampleRate of [44100, 48000]) {
  test(`analytic sine RMS, peak decay and envelope crest at ${sampleRate} Hz`, async () => {
    const { api, process } = await fixture(sampleRate);
    const samples = Float32Array.from({ length: sampleRate / 10 }, (_, i) =>
      0.8 * Math.sin(2 * Math.PI * 1000 * i / sampleRate));
    process(samples);
    close(api.get_rms_left(), 0.8 / Math.sqrt(2));
    close(api.get_rms_right(), 0.8 / Math.sqrt(2));
    // Exact sampled peak with the specified exponential release, not a true peak.
    let peak = 0;
    const decay = Math.exp(-1 / (sampleRate * 0.1));
    for (const sample of samples) peak = Math.max(Math.abs(sample), peak * decay);
    close(api.get_peak_left(), peak);
    close(api.get_crest_left(), 20 * Math.log10(peak / (0.8 / Math.sqrt(2))));
  });

  test(`DC stereo amplitudes and mono routing at ${sampleRate} Hz`, async () => {
    const { api, process } = await fixture(sampleRate);
    const frames = sampleRate / 10;
    const stereo = Float32Array.from({ length: frames * 2 }, (_, i) => i % 2 ? -0.25 : 0.5);
    process(stereo, 2);
    [0.5, 0.25, 0.5, 0.25, 0, 0].forEach((value, i) => close(readings(api)[i], value));
    api.reset_meters();
    process(new Float32Array(frames).fill(-0.75));
    [0.75, 0.75, 0.75, 0.75, 0, 0].forEach((value, i) => close(readings(api)[i], value));
  });

  test(`single impulse is captured, leaves RMS window and decays at ${sampleRate} Hz`, async () => {
    const { api, process } = await fixture(sampleRate);
    const windowFrames = sampleRate / 10;
    process(Float32Array.of(1));
    close(api.get_rms_left(), 1 / Math.sqrt(windowFrames));
    close(api.get_peak_left(), 1);
    close(api.get_crest_left(), 10 * Math.log10(windowFrames));
    process(new Float32Array(windowFrames - 1));
    close(api.get_rms_left(), 1 / Math.sqrt(windowFrames));
    close(api.get_peak_left(), Math.exp(-(windowFrames - 1) / (sampleRate * 0.1)));
    process(Float32Array.of(0));
    assert.equal(api.get_rms_left(), 0);
    close(api.get_peak_left(), Math.exp(-1));
    assert.equal(api.get_crest_left(), 0);
  });

  test(`silence, reset and initialization clear all history at ${sampleRate} Hz`, async () => {
    const { api, process } = await fixture(sampleRate);
    process(new Float32Array(sampleRate / 10));
    assert.deepEqual(readings(api), [0, 0, 0, 0, 0, 0]);
    process(Float32Array.of(1));
    api.reset_meters();
    assert.deepEqual(readings(api), [0, 0, 0, 0, 0, 0]);
    process(new Float32Array(sampleRate / 10).fill(0.5));
    process(new Float32Array(sampleRate / 10));
    assert.equal(api.get_rms_left(), 0);
    process(Float32Array.of(1));
    api.init_meters(10, 100, sampleRate);
    assert.deepEqual(readings(api), [0, 0, 0, 0, 0, 0]);
    process(Float32Array.of(1));
    close(api.get_rms_left(), 1 / Math.sqrt(Math.round(sampleRate / 100)));
  });

  test(`window startup and zero durations at ${sampleRate} Hz`, async () => {
    const { api, process } = await fixture(sampleRate, 10);
    process(Float32Array.of(0.5));
    close(api.get_rms_left(), 0.5 / Math.sqrt(Math.round(sampleRate / 100)));
    api.init_meters(0, 0, sampleRate);
    process(Float32Array.of(-0.5));
    close(api.get_rms_left(), 0.5);
    close(api.get_peak_left(), 0.5);
    process(Float32Array.of(0));
    assert.deepEqual(readings(api), [0, 0, 0, 0, 0, 0]);
  });

  test(`uneven processing chunks preserve readings at ${sampleRate} Hz`, async () => {
    const whole = await fixture(sampleRate);
    const chunked = await fixture(sampleRate);
    const frames = sampleRate / 2;
    const samples = Float32Array.from({ length: frames * 2 }, (_, i) =>
      i % 2 ? 0.3 * Math.cos(i * 0.023) : 0.7 * Math.sin(i * 0.031));
    whole.process(samples, 2);
    for (let offset = 0; offset < frames;) {
      const count = Math.min([1, 17, 128, 997][offset % 4], frames - offset);
      chunked.process(samples.subarray(offset * 2, (offset + count) * 2), 2);
      offset += count;
    }
    assert.deepEqual(readings(chunked.api), readings(whole.api));
  });
}
