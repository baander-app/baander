import assert from 'node:assert/strict';
import test from 'node:test';
import { loadWasm } from './wasm-fixture.mjs';

// Absolute tolerance for these bounded float32 convolution vectors.
const tolerance = 2e-6;

function closeSamples(actual, expected) {
  assert.equal(actual.length, expected.length);
  expected.forEach((sample, index) => {
    assert.ok(Math.abs(actual[index] - sample) <= tolerance,
      `sample ${index}: expected ${sample}, received ${actual[index]}`);
  });
}

function reference(input, impulse, channels) {
  const frames = input.length / channels;
  return Array.from({ length: (frames + impulse.length - 1) * channels }, (_, index) => {
    const frame = Math.floor(index / channels);
    const channel = index % channels;
    let sample = 0;
    for (let source = 0; source < frames; source++) {
      const tap = frame - source;
      if (tap >= 0 && tap < impulse.length) {
        sample += input[source * channels + channel] * impulse[tap];
      }
    }
    return sample;
  });
}

async function convolve(input, impulse, blockSize, channels, callSizes) {
  const api = await loadWasm('partitioned_convolver', {
    wasi_snapshot_preview1: { fd_close: () => 0, fd_write: () => 0, fd_seek: () => 0 },
  });
  const stream = Float32Array.from([
    ...input, ...new Array((impulse.length - 1) * channels).fill(0),
  ]);
  const allocations = [];
  let context;
  function allocate(samples) {
    const pointer = api.malloc(samples * Float32Array.BYTES_PER_ELEMENT);
    assert.notEqual(pointer, 0);
    allocations.push(pointer);
    return pointer;
  }
  try {
    const impulsePointer = allocate(impulse.length);
    new Float32Array(api.memory.buffer, impulsePointer, impulse.length).set(impulse);
    context = api.create_convolver(impulsePointer, impulse.length, blockSize, channels);
    assert.notEqual(context, 0);
    const inputPointer = allocate(stream.length);
    const outputPointer = allocate(stream.length);
    new Float32Array(api.memory.buffer, inputPointer, stream.length).set(stream);
    const frames = stream.length / channels;
    for (let offset = 0, call = 0; offset < frames; call++) {
      const count = Math.min(callSizes[call % callSizes.length], frames - offset);
      const byteOffset = offset * channels * Float32Array.BYTES_PER_ELEMENT;
      api.process(context, inputPointer + byteOffset, outputPointer + byteOffset, count);
      offset += count;
    }
    return Array.from(new Float32Array(api.memory.buffer, outputPointer, stream.length));
  } finally {
    if (context) api.destroy_convolver(context);
    for (const pointer of allocations) api.free(pointer);
  }
}

test('pending overlap emits the correct block and drains the complete tail', async () => {
  const output = await convolve([1, 2, 3, 4], [1, 2, 3], 2, 1, [4, 2]);
  closeSamples(output.slice(0, 4), [1, 4, 10, 16]);
  closeSamples(output.slice(4), [17, 12]);
});

for (const blockSize of [1, 2, 11]) {
  for (const [partition, callSizes] of [
    ['whole stream', [13]], ['single frames', [1]], ['uneven calls', [3, 1, 4, 2]],
  ]) {
    test(`long impulse retains its tail with block ${blockSize} and ${partition}`, async () => {
      const input = [1, -0.5, 0.25, 2, -1, 0.75, -0.25];
      const impulse = [0.25, -0.5, 0.75, 1, -0.125, 0.5, -0.25];
      closeSamples(await convolve(input, impulse, blockSize, 1, callSizes),
        reference(input, impulse, 1));
    });
  }
}

for (const channels of [1, 2]) {
  for (const gain of [1, -0.5]) {
    test(`one-tap gain ${gain} scales ${channels} channel(s) across calls`, async () => {
      const input = channels === 1 ? [1, -0.5, 0.25, 2, -1] :
        [1, -0.25, -0.5, 2, 0.25, -1, 2, 0.5, -1, 0.75];
      closeSamples(await convolve(input, [gain], 2, channels, [3, 1]),
        input.map(sample => sample * gain));
    });
  }
}

for (const silentChannel of [false, true]) {
  test(`stereo history stays independent with ${silentChannel ? 'one silent channel' : 'asymmetric channels'}`, async () => {
    const input = [1, -0.5, 0.25, 2, -1, 0.75, 0.5, -0.25, 2, 1];
    if (silentChannel) {
      for (let index = 1; index < input.length; index += 2) input[index] = 0;
    }
    const impulse = [0.5, -1, 0.25, 0.75, -0.125];
    closeSamples(await convolve(input, impulse, 2, 2, [1, 3, 2]),
      reference(input, impulse, 2));
  });
}
