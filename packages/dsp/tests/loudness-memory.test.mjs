import assert from 'node:assert/strict';
import test from 'node:test';
import { loadWasm } from './wasm-fixture.mjs';

// Higher-rate allocation smoke checks, separate from the 44.1/48 kHz
// independent accuracy vectors. Both history pools and caller buffers must fit.
for (const rate of [96000, 192000]) {
  test(`both loudness histories fit fixed WASM memory at ${rate} Hz`, async () => {
    const api = await loadWasm('loudness_r128');
    api.init_loudness(rate, 1);
    const bytes = api.memory.buffer.byteLength;
    assert.equal(bytes, 32 * 1024 * 1024);
    const pointer = api.malloc(8192 * 2 * Float32Array.BYTES_PER_ELEMENT);
    assert.ok(pointer > 0);
    try {
      for (let offset = 0; offset < rate * 3; offset += 8192) {
        const frames = Math.min(8192, rate * 3 - offset);
        const input = new Float32Array(api.memory.buffer, pointer, frames * 2);
        for (let i = 0; i < frames; i++) {
          const sample = 0.1 * Math.sin(2 * Math.PI * 1000 * (offset + i) / rate);
          input[2 * i] = input[2 * i + 1] = sample;
        }
        api.process_frames(pointer, frames, 2);
      }
      assert.equal(api.memory.buffer.byteLength, bytes);
      assert.ok(Number.isFinite(api.get_lufs_integrated()));
      assert.ok(Number.isFinite(api.get_lufs_momentary()));
      assert.ok(Number.isFinite(api.get_lufs_shortterm()));
      assert.equal(api.get_lra(), 0, 'one complete short-term observation has zero range');
      api.reset_loudness();
      assert.equal(api.memory.buffer.byteLength, bytes);
      assert.equal(api.get_lra(), 0);
    } finally { api.free(pointer); }
  });
}
