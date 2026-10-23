/* Encode a synthetic signal, decode it, and check the reconstruction:
 * exercises both classes, chunked input, and the declared delay. */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { AacDecoder, AacEncoder, version } from '../index.js';

function tones(samples, sampleRate, channels) {
  const pcm = new Float32Array(samples * channels);
  for (let i = 0; i < samples; i++) {
    const t = i / sampleRate;
    for (let c = 0; c < channels; c++) {
      const f = c ? 660 : 440;
      pcm[i * channels + c] = 0.3 * Math.sin(2 * Math.PI * f * t) + 0.1 * Math.sin(2 * Math.PI * 3000 * t);
    }
  }
  return pcm;
}

function snrDb(ref, out, offset) {
  let sig = 0;
  let err = 0;
  const n = Math.min(ref.length, out.length - offset);
  for (let i = 0; i < n; i++) {
    sig += ref[i] * ref[i];
    const d = ref[i] - out[i + offset];
    err += d * d;
  }
  return 10 * Math.log10(sig / Math.max(err, 1e-30));
}

test('reports a version', () => {
  assert.equal(typeof version, 'string');
});

test('encodes and decodes a stereo signal in uneven chunks', () => {
  const sampleRate = 44100;
  const channels = 2;
  const pcm = tones(sampleRate * 2, sampleRate, channels);
  const encoder = new AacEncoder({ sampleRate, channels, bitrate: 128000, mode: 'cbr' });
  assert.equal(encoder.frameSize, 1024);
  assert.equal(encoder.delay, 2048);

  const parts = [];
  for (let pos = 0; pos < pcm.length; pos += 3001) {
    parts.push(encoder.encode(pcm.subarray(pos, Math.min(pos + 3001, pcm.length))));
  }
  parts.push(encoder.flush());
  const stream = Buffer.concat(parts);
  assert.ok(stream.length > 1000, 'stream has content');
  assert.equal(stream[0], 0xff, 'ADTS syncword');

  const decoder = new AacDecoder({ sampleRate, channels });
  const out = decodeAll(decoder, stream, 777);
  assert.equal(decoder.errors, 0);
  assert.equal(decoder.hasSbr, false);
  assert.equal(decoder.sampleRate, sampleRate);
  const frames = Math.ceil(pcm.length / channels / 1024) + 2;
  assert.equal(out.length, frames * 1024 * channels, 'one frame per ADTS frame');
  const snr = snrDb(pcm, out, encoder.delay * channels);
  assert.ok(snr > 30, `reconstruction SNR ${snr.toFixed(1)} dB`);
});

function decodeAll(decoder, stream, chunk) {
  const decoded = [];
  for (let pos = 0; pos < stream.length; pos += chunk) {
    decoded.push(decoder.decode(stream.subarray(pos, Math.min(pos + chunk, stream.length))));
  }
  const out = new Float32Array(decoded.reduce((n, d) => n + d.length, 0));
  let at = 0;
  for (const d of decoded) {
    out.set(d, at);
    at += d.length;
  }
  return out;
}

test('encodes and decodes HE-AAC v1 and v2', () => {
  const sampleRate = 44100;
  const pcm = tones(sampleRate * 2, sampleRate, 2);
  for (const [profile, bitrate, streamChannels] of [
    ['he', 64000, 2],
    ['hev2', 32000, 1],
  ]) {
    const encoder = new AacEncoder({ sampleRate, channels: 2, bitrate, profile });
    assert.equal(encoder.profile, profile);
    assert.equal(encoder.frameSize, 2048);
    assert.equal(encoder.delay, 5635);
    const stream = Buffer.concat([encoder.encode(pcm), encoder.flush()]);

    const decoder = new AacDecoder({ channels: 0 });
    const out = decodeAll(decoder, stream, 1000);
    assert.equal(decoder.errors, 0);
    assert.ok(decoder.hasSbr, `${profile}: SBR decoded`);
    assert.equal(decoder.hasPs, profile === 'hev2');
    assert.equal(decoder.sampleRate, sampleRate);
    assert.equal(decoder.coreSampleRate, sampleRate / 2);
    assert.equal(decoder.frameSize, 2048);
    assert.equal(decoder.channels, 2);
    assert.equal(decoder.streamChannels, streamChannels);
    const frames = Math.ceil(pcm.length / 2 / 2048) + 3;
    assert.equal(out.length, frames * 2048 * 2, `${profile}: one frame per ADTS frame`);
    const snr = snrDb(pcm, out, encoder.delay * 2);
    assert.ok(snr > (profile === 'he' ? 15 : 8), `${profile}: reconstruction SNR ${snr.toFixed(1)} dB`);
  }
});

test('rejects unsupported configurations', () => {
  assert.throws(() => new AacEncoder({ sampleRate: 37000 }));
  assert.throws(() => new AacEncoder({ channels: 9 }));
  assert.throws(() => new AacEncoder({ mode: 'fast' }));
  assert.throws(() => new AacEncoder({ profile: 'main' }));
  assert.throws(() => new AacEncoder({ channels: 1, profile: 'hev2' }));
  assert.throws(() => new AacEncoder({ sampleRate: 16000, profile: 'he' }));
  assert.throws(() => new AacDecoder({ channels: 9 }));
  const encoder = new AacEncoder();
  assert.throws(() => encoder.encode(new Int16Array(10)));
});
