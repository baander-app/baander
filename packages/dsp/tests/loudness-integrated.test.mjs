import assert from 'node:assert/strict';
import test from 'node:test';
import { loadWasm } from './wasm-fixture.mjs';

// EBU Tech 3341 (2023), §2.3 and Table 1, cases 3–5:
// https://tech.ebu.ch/docs/tech/tech3341.pdf
// In-phase stereo 1 kHz tones; levels are per-channel peak dBFS.
const minimumRequirements = [
  { number: 3, segments: [[10, -36], [60, -23], [10, -36]] },
  { number: 4, segments: [[10, -72], [10, -36], [60, -23], [10, -36], [10, -72]] },
  { number: 5, segments: [[20, -26], [20.1, -20], [20, -26]] },
];
const variableChunks = [1, 127, 4093, 128, 8192, 17, 441, 480];

function close(actual, expected, tolerance) {
  assert.ok(Number.isFinite(actual) && Math.abs(actual - expected) <= tolerance,
    `expected ${expected} ± ${tolerance} LUFS, received ${actual}`);
}

async function fixture(sampleRate) {
  const api = await loadWasm('loudness_r128');
  api.init_loudness(sampleRate, 1);
  const pointer = api.malloc(8192 * 2 * Float32Array.BYTES_PER_ELEMENT);
  let position = 0;
  let chunkIndex = 0;
  return {
    api,
    tone(frames, level, chunks = variableChunks) {
      const amplitude = level === -Infinity ? 0 : 10 ** (level / 20);
      while (frames > 0) {
        const count = Math.min(frames, chunks[chunkIndex++ % chunks.length]);
        const buffer = new Float32Array(api.memory.buffer, pointer, count * 2);
        for (let i = 0; i < count; i++) {
          const value = amplitude * Math.sin(2 * Math.PI * 1000 * (position + i) / sampleRate);
          buffer[i * 2] = value;
          buffer[i * 2 + 1] = value;
        }
        api.process_frames(pointer, count, 2);
        position += count;
        frames -= count;
      }
    },
    reset() {
      api.reset_loudness();
      position = 0;
      chunkIndex = 0;
    },
    dispose() { api.free(pointer); },
  };
}

for (const sampleRate of [44100, 48000]) {
  for (const { number, segments } of minimumRequirements) {
    test(`EBU 3341 Table 1 case ${number}: integrated gating at ${sampleRate} Hz`, async () => {
      const meter = await fixture(sampleRate);
      try {
        for (const [seconds, level] of segments) {
          meter.tone(Math.round(seconds * sampleRate), level);
        }
        close(meter.api.get_lufs_integrated(), -23, 0.1);
      } finally { meter.dispose(); }
    });
  }

  test(`integrated loudness discards incomplete blocks at ${sampleRate} Hz`, async () => {
    const meter = await fixture(sampleRate);
    try {
      // 400 ms blocks start at zero, with a 100 ms hop. There is no
      // integrated observation until the first entire block is available.
      meter.tone(Math.round(sampleRate * 0.4) - 1, -23);
      assert.equal(meter.api.get_lufs_integrated(), -70);
      meter.tone(1, -23);
      close(meter.api.get_lufs_integrated(), -23, 0.1);
      const firstBlock = meter.api.get_lufs_integrated();
      meter.tone(Math.round(sampleRate * 0.1) - 1, -3);
      assert.equal(meter.api.get_lufs_integrated(), firstBlock,
        'an unfinished final block must not contribute');
      meter.tone(1, -3);
      assert.ok(meter.api.get_lufs_integrated() > firstBlock + 10,
        'the completed next block must contribute');
    } finally { meter.dispose(); }
  });

  test(`integrated programme survives five minutes of silence and resets at ${sampleRate} Hz`, async () => {
    const meter = await fixture(sampleRate);
    const fresh = await fixture(sampleRate);
    try {
      meter.tone(sampleRate * 2, -23);
      meter.tone(sampleRate, -Infinity);
      const programme = meter.api.get_lufs_integrated();
      close(programme, -23, 0.5); // Includes the three falling transition blocks.
      meter.tone(sampleRate * 302, -Infinity);
      assert.equal(meter.api.get_lufs_integrated(), programme,
        'silence must not evict the earlier programme');
      meter.reset();
      assert.equal(meter.api.get_lufs_integrated(), -70);
      meter.tone(sampleRate * 2, -33);
      fresh.tone(sampleRate * 2, -33, [128]);
      close(meter.api.get_lufs_integrated(), -33, 0.1);
      close(meter.api.get_lufs_integrated(), fresh.api.get_lufs_integrated(), 0.00001);
    } finally { meter.dispose(); fresh.dispose(); }
  });
}

// Independent offline direct-form-I evaluation of the published 48 kHz
// coefficients, using float32 input PCM, double-precision prefix energy sums,
// and BS.1770-5 Annex 1 equations (2), (4)–(7):
// Regenerate with: node packages/dsp/tests/reference/integrated-reference.mjs
// https://www.itu.int/dms_pubrec/itu-r/rec/bs/R-REC-BS.1770-5-202311-I!!PDF-E.pdf
// There are 397 complete 400 ms blocks. The two signals differ by only
// 0.003 dB; 397 and 201 blocks respectively survive the exact relative gate.
// FFmpeg ebur128's 0.01 LU histogram is unsuitable as a boundary oracle.
for (const [quiet, expected] of [[-32.787, -22.780801771655344], [-32.790, -20.044771661785145]]) {
  test(`relative gate preserves sub-centibel boundary at ${quiet} dBFS`, async () => {
    const meter = await fixture(48000);
    try {
      meter.tone(48000 * 20, -20);
      meter.tone(48000 * 20, quiet);
      close(meter.api.get_lufs_integrated(), expected, 0.002);
    } finally { meter.dispose(); }
  });
}
