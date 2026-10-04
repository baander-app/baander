import assert from 'node:assert/strict';
import test from 'node:test';
import { loadWasm } from '../wasm-fixture.mjs';

// The acceptance runner builds this fixture in its isolated temporary directory.
// Ordinary public-binary checks intentionally exclude this nested test suite.
for (const [name, order] of [
  ['ascending', Array.from({ length: 32 }, (_, i) => i + 1)],
  ['descending', Array.from({ length: 32 }, (_, i) => 32 - i)],
  ['mixed rotations', [16, 8, 12, 10, 11, 24, 20, 22, 21,
    ...Array.from({ length: 32 }, (_, i) => i + 1).filter(i => ![16, 8, 12, 10, 11, 24, 20, 22, 21].includes(i))]],
]) {
  test(`exact gating tree aggregates survive ${name} insertion`, async () => {
    const api = await loadWasm('loudness_gating_harness');
    api.init_loudness(48000, 1);
    const energies = [];
    for (const value of order) {
      api.gate_add(value);
      energies.push(value);
      assert.equal(api.gate_valid(), 1);
      for (const threshold of [0, 8, 8.5, 16, 31, 32]) {
        const accepted = energies.filter(v => v > threshold);
        assert.equal(api.gate_count(threshold), accepted.length);
        assert.equal(api.gate_sum(threshold), accepted.reduce((sum, v) => sum + v, 0));
      }
    }
    // A full distinct-key pool still accepts existing keys indefinitely.
    for (let i = 0; i < 100; i++) api.gate_add(16);
    assert.equal(api.gate_used(), 32);
    assert.equal(api.gate_exhausted(), 0);
    assert.equal(api.gate_count(0), 132);
    assert.equal(api.gate_sum(0), 528 + 1600);
    assert.equal(api.gate_count(16), 16);
    assert.equal(api.gate_valid(), 1);
    api.gate_add(33);
    assert.equal(api.gate_exhausted(), 1);
    api.gate_add(16);
    assert.equal(api.gate_count(0), 132);
    assert.equal(api.gate_valid(), 1);
  });
}

test('gating tree keeps strict thresholds for energies adjacent in double precision', async () => {
  const api = await loadWasm('loudness_gating_harness');
  api.init_loudness(48000, 1);
  const boundary = 1;
  for (const value of [1 - Number.EPSILON / 2, boundary, 1 + Number.EPSILON]) {
    api.gate_add(value);
  }
  assert.equal(api.gate_count(boundary), 1);
  assert.equal(api.gate_sum(boundary), 1 + Number.EPSILON);
  assert.equal(api.gate_used(), 3);
  assert.equal(api.gate_valid(), 1);
});

test('pool exhaustion reports unavailable integrated loudness until reset', async () => {
  const api = await loadWasm('loudness_gating_harness');
  api.init_loudness(48000, 1);
  const ptr = api.malloc(4800 * 4);
  try {
    for (let block = 0; block < 40; block++) {
      const input = new Float32Array(api.memory.buffer, ptr, 4800);
      for (let i = 0; i < input.length; i++) {
        const frame = block * input.length + i;
        input[i] = (0.05 + block * 0.002) * Math.sin(2 * Math.PI * 997 * frame / 48000);
      }
      api.process_frames(ptr, input.length, 1);
    }
    assert.equal(api.gate_exhausted(), 1);
    assert.ok(Number.isNaN(api.get_lufs_integrated()));
    assert.ok(Number.isFinite(api.get_lufs_momentary()));
    assert.ok(Number.isFinite(api.get_lufs_shortterm()));
    assert.ok(Number.isFinite(api.get_lra()));
    assert.ok(Number.isFinite(api.get_true_peak_dbfs()));
    new Float32Array(api.memory.buffer, ptr, 4800).fill(0);
    api.process_frames(ptr, 4800, 1);
    assert.ok(Number.isNaN(api.get_lufs_integrated()));
    api.reset_loudness();
    assert.equal(api.gate_exhausted(), 0);
    assert.equal(api.gate_used(), 0);
    assert.equal(api.get_lufs_integrated(), -70);
    // Reset can produce a fresh programme measurement again.
    const input = new Float32Array(api.memory.buffer, ptr, 4800);
    for (let i = 0; i < input.length; i++) input[i] = 0.1 * Math.sin(2 * Math.PI * 997 * i / 48000);
    for (let block = 0; block < 4; block++) api.process_frames(ptr, 4800, 1);
    assert.ok(Number.isFinite(api.get_lufs_integrated()) && api.get_lufs_integrated() > -70);
  } finally { api.free(ptr); }
});
