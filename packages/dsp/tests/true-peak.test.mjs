import assert from 'node:assert/strict';
import test from 'node:test';
import { loadWasm } from './wasm-fixture.mjs';

// EBU Tech 3341 (2023), Table 1, cases 15–19:
// https://tech.ebu.ch/docs/tech/tech3341.pdf
// Fractions, amplitudes, phases and asymmetric tolerances are specified there.
// A 10 ms raised-cosine fade prevents abrupt boundaries from becoming the peak.
// These generated vectors cover 15–19 only; 20–23 require separately validated
// anti-aliasing/downsampling vectors and are not claimed as covered here.
const cases = [
  { number: 15, divisor: 4, amplitude: 0.5, phase: 0, expected: -6 },
  { number: 16, divisor: 4, amplitude: 0.5, phase: 45, expected: -6 },
  { number: 17, divisor: 6, amplitude: 0.5, phase: 60, expected: -6 },
  { number: 18, divisor: 8, amplitude: 0.5, phase: 67.5, expected: -6 },
  { number: 19, divisor: 4, amplitude: 1.41, phase: 45, expected: 3 },
];
const chunkings = [[1], [128], [7, 129, 1, 64, 3, 257, 31]];

function tone(sampleRate, vector) {
  const frames = Math.round(sampleRate * 0.1);
  const fade = Math.round(sampleRate * 0.01);
  return Float32Array.from({ length: frames }, (_, frame) => {
    const distance = Math.min(frame, frames - 1 - frame);
    const envelope = distance >= fade ? 1 : 0.5 - 0.5 * Math.cos(Math.PI * distance / fade);
    return envelope * vector.amplitude * Math.sin(2 * Math.PI * frame / vector.divisor + vector.phase * Math.PI / 180);
  });
}

function close(actual, expected, tolerance, label) {
  assert.ok(Number.isFinite(actual) && Math.abs(actual - expected) <= tolerance,
    `${label}: expected ${expected} ± ${tolerance} dB, received ${actual}`);
}

async function meter(sampleRate = 48000, oversample = 4) {
  const api = await loadWasm('loudness_r128');
  api.init_loudness(sampleRate, oversample);
  const capacity = 8192;
  const pointer = api.malloc(capacity * 2 * Float32Array.BYTES_PER_ELEMENT);
  return {
    api,
    process(samples, channels = 1) {
      const frames = samples.length / channels;
      assert.ok(Number.isInteger(frames) && frames <= capacity);
      new Float32Array(api.memory.buffer, pointer, samples.length).set(samples);
      api.process_frames(pointer, frames, channels);
      return api.get_true_peak_dbfs();
    },
    dispose() { api.free(pointer); },
  };
}

async function measure(samples, { sampleRate = 48000, oversample = 4, chunks = [128], channels = 2, layout = 'dual' } = {}) {
  const instance = await meter(sampleRate, oversample);
  let maximum = -Infinity;
  let chunk = 0;
  // A causal 12-tap interpolator needs the last eleven zero input frames to
  // expose its tail. Preserve the per-call getter contract by accumulating max.
  const frames = samples.length + 12;
  try {
    for (let offset = 0; offset < frames;) {
      const count = Math.min(chunks[chunk++ % chunks.length], frames - offset);
      const interleaved = new Float32Array(count * channels);
      for (let frame = 0; frame < count; frame++) {
        const value = samples[offset + frame] ?? 0;
        if (channels === 1 || layout !== 'right') interleaved[frame * channels] = value;
        if (channels === 2 && layout !== 'left') interleaved[frame * channels + 1] = layout === 'opposite' ? -value : value;
      }
      maximum = Math.max(maximum, instance.process(interleaved, channels));
      offset += count;
    }
    return maximum;
  } finally { instance.dispose(); }
}

for (const sampleRate of [44100, 48000]) {
  for (const vector of cases) {
    test(`EBU 3341 case ${vector.number} true peak at ${sampleRate} Hz survives arbitrary chunks`, async () => {
      const samples = tone(sampleRate, vector);
      const readings = [];
      for (const chunks of chunkings) {
        const reading = await measure(samples, { sampleRate, chunks });
        assert.ok(reading >= vector.expected - 0.4 && reading <= vector.expected + 0.2,
          `case ${vector.number}, chunks ${chunks}: expected [${vector.expected - 0.4}, ${vector.expected + 0.2}] dBTP, received ${reading}`);
        readings.push(reading);
      }
      readings.forEach(reading => close(reading, readings[0], 0.00001, 'chunk invariance'));
      if (vector.number === 16 || vector.number === 19) {
        const samplePeak = 20 * Math.log10(samples.reduce((peak, value) => Math.max(peak, Math.abs(value)), 0));
        assert.ok(readings[0] - samplePeak > 2.5, `intersample reconstruction must exceed ${samplePeak} dBFS sample peak`);
      }
    });
  }
}

test('channel peaks remain independent for mono, either stereo side, dual mono and opposite phases', async () => {
  const samples = tone(48000, cases[1]);
  const mono = await measure(samples, { channels: 1, chunks: chunkings[2] });
  for (const layout of ['left', 'right', 'dual', 'opposite']) {
    close(await measure(samples, { layout, chunks: chunkings[2] }), mono, 0.00001, layout);
  }
});

test('1x mode measures raw samples without reconstructed peaks', async () => {
  const samples = tone(48000, cases[1]);
  const expected = 20 * Math.log10(samples.reduce((peak, value) => Math.max(peak, Math.abs(value)), 0));
  for (const chunks of chunkings) close(await measure(samples, { oversample: 1, chunks }), expected, 0.00001, 'sample peak');
});

test('finite two-sample pulse agrees with independent ideal sinc reconstruction', async () => {
  // For samples [0.5, 0.5] surrounded by zeros, the ideal bandlimited
  // reconstruction is 0.5*sinc(t) + 0.5*sinc(t-1). Its maximum is 2/pi
  // at t=0.5. This analytic oracle uses no production FIR coefficients.
  // Allow 0.4 dB for the finite FIR's approximation and phase sampling.
  const expected = 20 * Math.log10(2 / Math.PI);
  for (const oversample of [2, 4]) {
    for (const chunks of [[1], [2], [128], chunkings[2]]) {
      close(await measure(new Float32Array([0.5, 0.5]), { chunks, oversample }), expected, 0.4, `${oversample}x ideal sinc pulse`);
    }
  }
});

test('delayed FIR peak and tail cross calls, expire, and reset clears pending history', async () => {
  const instance = await meter();
  try {
    const initial = instance.process(new Float32Array([0.5, 0.5]));
    const tail = Array.from({ length: 12 }, () => instance.process(new Float32Array([0])));
    assert.ok(Math.max(...tail) > initial + 0.2, 'two adjacent samples have a delayed intersample overshoot');
    assert.ok(tail.slice(0, 10).some(value => value > -100), 'zero input still emits the retained FIR tail');
    close(instance.process(new Float32Array([0])), -180, 0.0001, 'expired tail');
    instance.process(new Float32Array([0.5, 0.5]));
    instance.api.reset_loudness();
    close(instance.process(new Float32Array(12)), -180, 0.0001, 'reset pending tail');
    const afterReset = instance.process(new Float32Array([0.5, 0.5]));
    close(afterReset, initial, 0.00001, 'fresh FIR state');
  } finally { instance.dispose(); }
});

test('missing right channel advances its FIR history with silence', async () => {
  const instance = await meter();
  try {
    instance.process(new Float32Array([0, 0.5, 0, 0.5]), 2);
    const pending = Array.from({ length: 12 }, () => instance.process(new Float32Array([0]), 1));
    assert.ok(Math.max(...pending) > -5.8, 'right channel reconstruction continues through mono silence');
    close(instance.process(new Float32Array([0, 0]), 2), -180, 0.0001, 'right tail cannot resume after layout change');
  } finally { instance.dispose(); }
});
