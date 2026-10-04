import assert from 'node:assert/strict';
import test from 'node:test';
import { loadWasm } from './wasm-fixture.mjs';

// ITU-R BS.1770-5, Annex 1, Tables 1/2 and equation (2):
// https://www.itu.int/dms_pubrec/itu-r/rec/bs/R-REC-BS.1770-5-202311-I!!PDF-E.pdf
// These published 48 kHz coefficients are independent of the production designer.
const stages48 = [
  { b: [1.53512485958697, -2.69169618940638, 1.19839281085285], a: [1, -1.69065929318241, 0.73248077421585] },
  { b: [1, -2, 1], a: [1, -1.99004745483398, 0.99007225036621] },
];

function polynomialPower(coefficients, omega) {
  let real = 0;
  let imaginary = 0;
  coefficients.forEach((coefficient, delay) => {
    real += coefficient * Math.cos(delay * omega);
    imaginary -= coefficient * Math.sin(delay * omega);
  });
  return real * real + imaginary * imaginary;
}

function monoTone48(frequency, amplitude = 0.1) {
  const omega = 2 * Math.PI * frequency / 48000;
  const gain = stages48.reduce((power, stage) =>
    power * polynomialPower(stage.b, omega) / polynomialPower(stage.a, omega), 1);
  return -0.691 + 10 * Math.log10(amplitude * amplitude * gain / 2);
}

// Independently recorded with FFmpeg 8.1.2 ebur128, final M/S metadata:
// ffmpeg -f lavfi -i 'aevalsrc=0.1*sin(2*PI*F*t):s=44100:d=4' \
//   -af 'ebur128=metadata=1,ametadata=print' -f null -
// FFmpeg's reference implementation:
// https://github.com/FFmpeg/FFmpeg/blob/n8.1.2/libavfilter/f_ebur128.c
// 0.01 LU allows its three-decimal metadata and the small RLB gain difference
// between its rate conversion and a gain-preserving conversion of the ITU table.
const monoTone441 = new Map([[20, -36.973], [60, -26.597], [1000, -23.001], [10000, -19.655]]);

function close(actual, expected, tolerance, description) {
  assert.ok(Number.isFinite(actual) && Math.abs(actual - expected) <= tolerance,
    `${description}: expected ${expected} ± ${tolerance} LUFS, received ${actual}`);
}

async function measure(sampleRate, channels, generate, seconds = 4) {
  const api = await loadWasm('loudness_r128');
  api.init_loudness(sampleRate, 1);
  const chunkSize = 1024;
  const pointer = api.malloc(chunkSize * channels * Float32Array.BYTES_PER_ELEMENT);
  try {
    for (let offset = 0; offset < Math.round(sampleRate * seconds); offset += chunkSize) {
      const frames = Math.min(chunkSize, Math.round(sampleRate * seconds) - offset);
      const samples = new Float32Array(api.memory.buffer, pointer, frames * channels);
      for (let frame = 0; frame < frames; frame++) {
        for (let channel = 0; channel < channels; channel++) {
          samples[frame * channels + channel] = generate(offset + frame, channel);
        }
      }
      api.process_frames(pointer, frames, channels);
    }
    return [api.get_lufs_momentary(), api.get_lufs_shortterm()];
  } finally {
    api.free(pointer);
  }
}

for (const sampleRate of [44100, 48000]) {
  for (const frequency of [20, 60, 1000, 10000]) {
    test(`K weighting matches independent ${frequency} Hz mono reference at ${sampleRate} Hz`, async () => {
      const expected = sampleRate === 48000 ? monoTone48(frequency) : monoTone441.get(frequency);
      const readings = await measure(sampleRate, 1,
        frame => 0.1 * Math.sin(2 * Math.PI * frequency * frame / sampleRate));
      readings.forEach((reading, index) => close(reading, expected, 0.01, index ? 'S' : 'M'));
    });
  }

  test(`channel energies sum independently at ${sampleRate} Hz`, async () => {
    const tone = frame => 0.1 * Math.sin(2 * Math.PI * 1000 * frame / sampleRate);
    const mono = await measure(sampleRate, 1, tone);
    const left = await measure(sampleRate, 2, (frame, channel) => channel === 0 ? tone(frame) : 0);
    const right = await measure(sampleRate, 2, (frame, channel) => channel === 1 ? tone(frame) : 0);
    const dualMono = await measure(sampleRate, 2, tone);
    const oppositePhase = await measure(sampleRate, 2, (frame, channel) => tone(frame) * (channel ? -1 : 1));
    mono.forEach((value, index) => {
      close(left[index], value, 0.0001, 'left only equals mono');
      close(right[index], value, 0.0001, 'right only equals mono');
      close(dualMono[index] - value, 10 * Math.log10(2), 0.0001, 'dual mono energy gain');
      close(oppositePhase[index], dualMono[index], 0.0001, 'phase does not cancel channel energies');
    });
  });

  // EBU Tech 3341 (2023), Table 1, test signals 1 and 2:
  // https://tech.ebu.ch/docs/tech/tech3341.pdf
  // This validates only M/S calibration, not integrated gating or EBU compliance.
  for (const level of [-23, -33]) {
    test(`EBU 1000 Hz stereo calibration reads ${level} LUFS at ${sampleRate} Hz`, async () => {
      const amplitude = 10 ** (level / 20);
      const readings = await measure(sampleRate, 2,
        frame => amplitude * Math.sin(2 * Math.PI * 1000 * frame / sampleRate), 20);
      readings.forEach((reading, index) => close(reading, level, 0.1, index ? 'S' : 'M'));
    });
  }
}

// Direct convolution recurrence from the published transfer functions, rather
// than reproducing the production biquad state representation.
function referenceImpulseEnergy(frames) {
  let samples = new Float64Array(frames);
  samples[0] = 0.5;
  for (const { b, a } of stages48) {
    const output = new Float64Array(frames);
    for (let frame = 0; frame < frames; frame++) {
      for (let delay = 0; delay <= 2 && delay <= frame; delay++) {
        output[frame] += b[delay] * samples[frame - delay];
        if (delay > 0) output[frame] -= a[delay] * output[frame - delay];
      }
    }
    samples = output;
  }
  return samples.reduce((energy, sample) => energy + sample * sample, 0) / frames;
}

test('48 kHz impulse energy matches the published cascade for full M and S windows', async () => {
  for (const [seconds, index] of [[0.4, 0], [3, 1]]) {
    const readings = await measure(48000, 1, frame => frame === 0 ? 0.5 : 0, seconds);
    const expected = -0.691 + 10 * Math.log10(referenceImpulseEnergy(Math.round(seconds * 48000)));
    close(readings[index], expected, 0.001, index ? 'S impulse' : 'M impulse');
  }
});

test('mono silence advances the inactive right filter before stereo resumes', async () => {
  const api = await loadWasm('loudness_r128');
  api.init_loudness(48000, 1);
  const pointer = api.malloc(128 * 2 * Float32Array.BYTES_PER_ELEMENT);
  try {
    let samples = new Float32Array(api.memory.buffer, pointer, 256);
    samples.fill(0);
    samples[1] = 0.5;
    api.process_frames(pointer, 1, 2);
    samples.fill(0);
    for (let frame = 0; frame < 48000 * 4; frame += 128) {
      api.process_frames(pointer, 128, 1);
    }
    const silent = [api.get_lufs_momentary(), api.get_lufs_shortterm()];
    // A frozen right biquad would resume its impulse tail on this frame and
    // inject audible energy despite both channels having been silent for 4 s.
    api.process_frames(pointer, 1, 2);
    close(api.get_lufs_momentary(), silent[0], 0.0001, 'M after layout change');
    close(api.get_lufs_shortterm(), silent[1], 0.0001, 'S after layout change');
    close(silent[0], -120.691, 0.0001, 'M silence floor');
    close(silent[1], -120.691, 0.0001, 'S silence floor');
  } finally {
    api.free(pointer);
  }
});
