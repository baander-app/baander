import assert from 'node:assert/strict';
import test from 'node:test';
import { loadWasm } from './wasm-fixture.mjs';

// EBU Tech 3342 (2023), sections 3.1/5 and Table 1:
// https://tech.ebu.ch/docs/tech/tech3342.pdf
// Full 3 s windows at 10 Hz; inclusive -70 LUFS and mean-power-minus-20 LU
// gates; the published MATLAB reference selects round((n-1)*p) order statistics.
// These are synthetic Table 1 cases 1–4, not the authentic programme cases 5/6.
function range(levels) {
  const absolute = levels.filter(level => level >= -70);
  if (!absolute.length) return 0;
  const threshold = 10 * Math.log10(absolute.reduce((sum, level) => sum + 10 ** (level / 10), 0)
    / absolute.length) - 20;
  const gated = absolute.filter(level => level >= threshold).sort((a, b) => a - b);
  if (!gated.length) return 0;
  return gated[Math.round((gated.length - 1) * 0.95)] - gated[Math.round((gated.length - 1) * 0.1)];
}

// Independent sample-domain reference: published ITU-R BS.1770-5 48 kHz
// transfer functions, evaluated as direct input/output recurrences. This uses
// neither production filter designers nor WASM M/S readings as the oracle.
const stages48 = [
  { b: [1.53512485958697, -2.69169618940638, 1.19839281085285], a: [1, -1.69065929318241, 0.73248077421585] },
  { b: [1, -2, 1], a: [1, -1.99004745483398, 0.99007225036621] },
];

function reference48(frames, signal) {
  const states = stages48.map(() => ({ x: [0, 0], y: [0, 0] }));
  const window = new Float64Array(48000 * 3);
  const levels = [];
  let energy = 0;
  for (let frame = 0; frame < frames; frame++) {
    let sample = Math.fround(signal(frame));
    stages48.forEach(({ b, a }, index) => {
      const { x, y } = states[index];
      const output = b[0] * sample + b[1] * x[0] + b[2] * x[1] - a[1] * y[0] - a[2] * y[1];
      x[1] = x[0]; x[0] = sample;
      y[1] = y[0]; y[0] = output;
      sample = output;
    });
    const index = frame % window.length;
    energy += 2 * sample * sample - window[index];
    window[index] = 2 * sample * sample;
    if (frame + 1 >= window.length && (frame + 1 - window.length) % 4800 === 0) {
      levels.push(-0.691 + 10 * Math.log10(Math.max(energy / window.length, 1e-12)));
    }
  }
  return range(levels);
}

function close(actual, expected, tolerance = 0.003) {
  assert.ok(Number.isFinite(actual) && Math.abs(actual - expected) <= tolerance,
    `expected ${expected} ± ${tolerance} LU, received ${actual}`);
}

async function meter(sampleRate) {
  const api = await loadWasm('loudness_r128');
  api.init_loudness(sampleRate, 1);
  const pointer = api.malloc(8192 * 2 * Float32Array.BYTES_PER_ELEMENT);
  let position = 0;
  return {
    api,
    feed(frames, signal, chunk = 8192) {
      for (let offset = 0; offset < frames; offset += chunk) {
        const count = Math.min(chunk, frames - offset);
        const samples = new Float32Array(api.memory.buffer, pointer, count * 2);
        for (let frame = 0; frame < count; frame++) {
          samples[frame * 2] = samples[frame * 2 + 1] = signal(position + offset + frame);
        }
        api.process_frames(pointer, count, 2);
      }
      position += frames;
      return api.get_lra();
    },
    reset() { api.reset_loudness(); position = 0; },
    dispose() { api.free(pointer); },
  };
}

function tone(sampleRate, levels, segmentSeconds = 20) {
  const amplitudes = levels.map(level => 10 ** (level / 20));
  return frame => amplitudes[Math.min(amplitudes.length - 1, Math.floor(frame / (sampleRate * segmentSeconds)))]
    * Math.sin(2 * Math.PI * 1000 * frame / sampleRate);
}

for (const sampleRate of [44100, 48000]) {
  for (const [index, levels, expected] of [
    [1, [-20, -30], 10], [2, [-20, -15], 5],
    [3, [-40, -20], 20], [4, [-50, -35, -20, -35, -50], 15],
  ]) {
    test(`Tech 3342 Table 1 case ${index} at ${sampleRate} Hz`, async () => {
      const actual = await meter(sampleRate);
      try {
        actual.feed(sampleRate * levels.length * 20, tone(sampleRate, levels));
        // Tech 3342 section 5 asks file measurements to flush 1.5 s of silence.
        close(actual.feed(sampleRate * 1.5, () => 0), expected, 1);
      } finally { actual.dispose(); }
    });
  }

  test(`LRA waits for full 3 s windows and advances every 100 ms at ${sampleRate} Hz`, async () => {
    const actual = await meter(sampleRate);
    const generate = tone(sampleRate, [-35, -15], 2.9);
    try {
      close(actual.feed(sampleRate * 3 - 1, generate), 0, 0.00001);
      close(actual.feed(1, generate), 0, 0.00001);
      close(actual.feed(sampleRate / 10 - 1, generate), 0, 0.00001);
      const secondWindow = actual.feed(1, generate);
      assert.ok(secondWindow > 1, `second full window must change LRA, received ${secondWindow}`);
      if (sampleRate === 48000) close(secondWindow, reference48(sampleRate * 3.1, generate));
    } finally { actual.dispose(); }
  });

  test(`LRA retains audible programme across more than five minutes of silence at ${sampleRate} Hz`, async () => {
    const actual = await meter(sampleRate);
    try {
      actual.feed(sampleRate * 40, tone(sampleRate, [-20, -30]));
      const retained = actual.feed(sampleRate * 4, () => 0);
      close(retained, 10, 0.05);
      close(actual.feed(sampleRate * 302, () => 0), retained, 0.00001);
    } finally { actual.dispose(); }
  });

  test(`LRA reset and irregular chunks preserve the measurement at ${sampleRate} Hz`, async () => {
    const small = await meter(sampleRate);
    const large = await meter(sampleRate);
    const generate = tone(sampleRate, [-35, -15, -28, -22], 0.7);
    try {
      const expected = large.feed(sampleRate * 12, generate, 8192);
      close(small.feed(sampleRate * 12, generate, 137), expected, 0.00001);
      small.reset();
      close(small.api.get_lra(), 0, 0.00001);
      close(small.feed(sampleRate * 3 - 1, generate, 137), 0, 0.00001);
      close(small.feed(sampleRate * 9 + 1, generate, 137), expected, 0.00001);
      if (sampleRate === 48000) close(expected, reference48(sampleRate * 12, generate));
    } finally { small.dispose(); large.dispose(); }
  });
}

test('3 s short-term LRA matches independent filtering for subsecond level changes', async () => {
  const sampleRate = 48000;
  const pattern = tone(sampleRate, [-18, -35, -22, -30, -15, -40, -25, -20], 0.45);
  const generate = frame => pattern(frame % (sampleRate * 3.6));
  const expected = reference48(sampleRate * 12, generate);
  assert.ok(expected > 0.5 && expected < 10, `crafted reference must distinguish short-term variation: ${expected}`);
  const actual = await meter(sampleRate);
  try { close(actual.feed(sampleRate * 12, generate, 511), expected); }
  finally { actual.dispose(); }
});

test('absolute and relative gates exclude quiet background rather than increasing LRA', async () => {
  const sampleRate = 48000;
  // Includes deterministic broadband noise above the absolute gate, noise below
  // it, and foreground. The stateless hash keeps all chunk sizes reproducible.
  const generate = frame => {
    const seconds = frame / sampleRate;
    const level = seconds < 20 ? -20 : seconds < 40 ? -60 : seconds < 60 ? -90 : -25;
    if (seconds < 20 || seconds >= 60) {
      return 10 ** (level / 20) * Math.sin(2 * Math.PI * 1000 * seconds);
    }
    let noise = Math.imul(frame ^ 0x9e3779b9, 0x21f0aaad);
    noise = Math.imul(noise ^ (noise >>> 15), 0x735a2d97);
    return 10 ** (level / 20) * ((noise >>> 0) / 0x80000000 - 1);
  };
  const expected = reference48(sampleRate * 80, generate);
  close(expected, 5, 0.05);
  const actual = await meter(sampleRate);
  try { close(actual.feed(sampleRate * 80, generate), expected); }
  finally { actual.dispose(); }
});
