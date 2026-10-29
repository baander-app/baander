/* Encode a synthetic signal, decode it, and check the reconstruction:
 * exercises both classes, chunked input, and the declared delay. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
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

test('carries a stream over LOAS, raw access units and 960-sample frames', () => {
  const sampleRate = 48000;
  const pcm = tones(sampleRate, sampleRate, 2);
  const reference = new AacEncoder({ sampleRate, channels: 2, bitrate: 128000 });
  const adts = Buffer.concat([reference.encode(pcm), reference.flush()]);
  const expected = decodeAll(new AacDecoder({ sampleRate, channels: 2 }), adts, adts.length);

  const loasEncoder = new AacEncoder({ sampleRate, channels: 2, bitrate: 128000, transport: 'loas', crc: true });
  const loas = Buffer.concat([loasEncoder.encode(pcm), loasEncoder.flush()]);
  assert.equal(loas[0], 0x56, 'LOAS sync');
  const loasDecoder = new AacDecoder({ channels: 2 });
  const fromLoas = decodeAll(loasDecoder, loas, 333);
  assert.equal(loasDecoder.errors, 0);
  assert.equal(loasDecoder.concealedFrames, 0);
  assert.deepEqual(fromLoas, expected, 'LOAS decodes as the ADTS stream');

  const rawEncoder = new AacEncoder({ sampleRate, channels: 2, bitrate: 128000, transport: 'raw' });
  const config = rawEncoder.config;
  assert.ok(config.length >= 2, 'AudioSpecificConfig');
  const rawDecoder = new AacDecoder({ channels: 2, transport: 'raw', config });
  const units = [];
  for (let pos = 0; pos + 2048 <= pcm.length; pos += 2048) {
    units.push(rawEncoder.encode(pcm.subarray(pos, pos + 2048)));
  }
  const tail = rawEncoder.flush();
  assert.equal(rawEncoder.unitSizes.length, 2, 'the flush yields two access units');
  let at = 0;
  for (const size of rawEncoder.unitSizes) {
    units.push(tail.subarray(at, at + size));
    at += size;
  }
  let decodedUnits = 0;
  for (const unit of units) {
    if (unit.length) decodedUnits += rawDecoder.decode(unit).length / 2 / 1024;
  }
  assert.equal(rawDecoder.errors, 0);
  assert.equal(decodedUnits, Math.floor(pcm.length / 2 / 1024) + 2, 'one block per access unit');

  const shortEncoder = new AacEncoder({ sampleRate, channels: 2, bitrate: 128000, frameLength: 960 });
  assert.equal(shortEncoder.frameSize, 960);
  assert.equal(shortEncoder.delay, 1920);
  const short = Buffer.concat([shortEncoder.encode(pcm), shortEncoder.flush()]);
  const shortDecoder = new AacDecoder({ channels: 2, frameLength: 960 });
  const fromShort = decodeAll(shortDecoder, short, short.length);
  assert.equal(shortDecoder.frameSize, 960);
  assert.equal(shortDecoder.errors, 0);
  const snr = snrDb(pcm, fromShort, shortEncoder.delay * 2);
  assert.ok(snr > 30, `960-frame reconstruction SNR ${snr.toFixed(1)} dB`);
});

test('conceals damaged frames and limits the output', () => {
  const sampleRate = 44100;
  const pcm = tones(sampleRate, sampleRate, 2);
  const encoder = new AacEncoder({ sampleRate, channels: 2, bitrate: 128000 });
  const stream = Buffer.concat([encoder.encode(pcm), encoder.flush()]);
  const damaged = Buffer.from(stream);
  for (let i = 400; i < 420; i++) damaged[i] ^= 0x5a;

  const concealing = new AacDecoder({ channels: 2 });
  const out = decodeAll(concealing, damaged, damaged.length);
  assert.equal(concealing.errors, 0);
  assert.ok(concealing.concealedFrames >= 1, 'damaged frame concealed');
  assert.equal(out.length, decodeAll(new AacDecoder({ channels: 2 }), stream, stream.length).length);

  const strict = new AacDecoder({ channels: 2, concealment: 'off' });
  decodeAll(strict, damaged, damaged.length);
  assert.ok(strict.errors >= 1, 'damaged frame reported with concealment off');
  assert.equal(strict.concealedFrames, 0);

  const limited = new AacDecoder({ channels: 2, limiter: { attackMs: 5, releaseMs: 50 } });
  assert.equal(limited.delay, Math.floor(sampleRate * 5 / 1000));
  const loud = new Float32Array(pcm.length);
  for (let i = 0; i < pcm.length; i++) loud[i] = pcm[i] * 4;
  const loudEncoder = new AacEncoder({ sampleRate, channels: 2, bitrate: 256000 });
  const loudStream = Buffer.concat([loudEncoder.encode(loud), loudEncoder.flush()]);
  const limitedOut = decodeAll(limited, loudStream, loudStream.length);
  let peak = 0;
  for (const v of limitedOut) peak = Math.max(peak, Math.abs(v));
  assert.ok(peak <= 1.0, `limited peak ${peak}`);
  assert.equal(limited.channelMode, 'default');
});

test('names the 7.1 layout of an eight-channel stream', () => {
  const sampleRate = 48000;
  const pcm = tones(sampleRate / 2, sampleRate, 8);
  for (const mode of ['7.1', '7.1-front', '7.1-top', '7.1-rear']) {
    const encoder = new AacEncoder({ sampleRate, channels: 8, bitrate: 512000, channelMode: mode });
    const stream = Buffer.concat([encoder.encode(pcm), encoder.flush()]);
    const decoder = new AacDecoder({ channels: 0 });
    decodeAll(decoder, stream, stream.length);
    assert.equal(decoder.errors, 0);
    assert.equal(decoder.channels, 8);
    assert.equal(decoder.channelMode, mode, `mode ${mode}`);
  }
  assert.throws(() => new AacEncoder({ channels: 6, channelMode: '7.1-top' }));
  assert.throws(() => new AacEncoder({ transport: 'detect' }));
  assert.throws(() => new AacDecoder({ concealment: 'maybe' }));
});

test('encodes and decodes the low-delay profiles', () => {
  const sampleRate = 48000;
  const pcm = tones(sampleRate, sampleRate, 2);
  for (const [profile, frameLength, delay] of [
    ['ld', 512, 1024],
    ['ld', 480, 960],
    ['eld', 512, 768],
  ]) {
    const encoder = new AacEncoder({ sampleRate, channels: 2, bitrate: 128000, profile, frameLength });
    assert.equal(encoder.profile, profile);
    assert.equal(encoder.frameSize, frameLength);
    assert.equal(encoder.delay, delay, `${profile} ${frameLength}: delay`);
    const stream = Buffer.concat([encoder.encode(pcm), encoder.flush()]);
    assert.equal(stream[0], 0x56, `${profile}: LOAS by default`);
    const decoder = new AacDecoder({ channels: 2 });
    const out = decodeAll(decoder, stream, 500);
    assert.equal(decoder.errors, 0);
    assert.equal(decoder.objectType, profile);
    assert.equal(decoder.frameSize, frameLength);
    const snr = snrDb(pcm, out, encoder.delay * 2);
    assert.ok(snr > 25, `${profile} ${frameLength}: reconstruction SNR ${snr.toFixed(1)} dB`);
  }
  const eldSbr = new AacEncoder({ sampleRate, channels: 2, bitrate: 64000, profile: 'eld', ldSbr: true });
  assert.equal(eldSbr.frameSize, 1024, 'dual-rate LD-SBR frame');
  const sbrStream = Buffer.concat([eldSbr.encode(pcm), eldSbr.flush()]);
  const sbrDecoder = new AacDecoder({ channels: 2 });
  const sbrOut = decodeAll(sbrDecoder, sbrStream, 700);
  assert.equal(sbrDecoder.errors, 0);
  assert.equal(sbrDecoder.hasSbr, true, 'LD-SBR decoded');
  assert.equal(sbrDecoder.coreSampleRate, sampleRate / 2);
  assert.equal(sbrDecoder.sampleRate, sampleRate);
  assert.ok(snrDb(pcm, sbrOut, eldSbr.delay * 2) > 8, 'ELD with LD-SBR reconstructs');
  assert.throws(() => new AacEncoder({ profile: 'ld', transport: 'adts' }));
  assert.throws(() => new AacEncoder({ profile: 'lc', frameLength: 512 }));
  assert.throws(() => new AacEncoder({ profile: 'lc', ldSbr: true }));
  for (const profile of ['ld', 'eld']) {
    assert.throws(
      () => new AacEncoder({ profile, metadata: { drcProfile: 'film-standard' } }),
      /metadata is not available for the ld and eld profiles/,
    );
  }
});

test('refuses numeric options that are not numbers', () => {
  assert.throws(() => new AacDecoder({ eldDownscale: true }), /eldDownscale must be a number/);
  assert.throws(() => new AacDecoder({ frameLength: {} }), /frameLength must be a number/);
  assert.throws(() => new AacDecoder({ drc: { boost: '50' } }), /drc\.boost must be a number/);
  assert.throws(() => new AacDecoder({ drc: { cut: false } }), /drc\.cut must be a number/);
  assert.throws(() => new AacDecoder({ drc: { targetRefLevel: null } }), /targetRefLevel must be a number/);
  assert.throws(() => new AacEncoder({ frameLength: '960' }), /frameLength must be a number/);
  assert.throws(
    () => new AacEncoder({ metadata: { drcProfile: 'speech', programRefLevel: true } }),
    /programRefLevel must be a number/,
  );
  assert.ok(new AacDecoder({ eldDownscale: 1, frameLength: 960, drc: { boost: 50 } }));
});

test('writes and applies dynamic range control, and runs the afterburner', () => {
  const sampleRate = 44100;
  const pcm = tones(sampleRate, sampleRate, 2);
  const plain = new AacEncoder({ sampleRate, channels: 2, bitrate: 96000 });
  const plainStream = Buffer.concat([plain.encode(pcm), plain.flush()]);
  const metadata = new AacEncoder({
    sampleRate, channels: 2, bitrate: 96000,
    metadata: { drcProfile: 'film-standard', programRefLevel: 124 },
  });
  const stream = Buffer.concat([metadata.encode(pcm), metadata.flush()]);
  const plainDecoder = new AacDecoder({ channels: 2 });
  decodeAll(plainDecoder, plainStream, plainStream.length);
  assert.equal(plainDecoder.hasDrc, false);
  assert.equal(plainDecoder.programRefLevel, -1);

  const off = new AacDecoder({ channels: 2 });
  const offOut = decodeAll(off, stream, stream.length);
  assert.equal(off.hasDrc, true);
  assert.equal(off.programRefLevel, 124);
  const gainDb = (decoder) => {
    const out = decodeAll(decoder, stream, stream.length);
    assert.equal(decoder.errors, 0);
    let offRms = 0;
    let onRms = 0;
    for (let i = 0; i < offOut.length; i++) {
      offRms += offOut[i] * offOut[i];
      onRms += out[i] * out[i];
    }
    return 10 * Math.log10(onRms / offRms);
  };
  /* The signal sits about 18 dB above the reference level, where the film
   * standard curve cuts; the target level then lifts everything by 6 dB. */
  const cut = gainDb(new AacDecoder({ channels: 2, drc: true }));
  assert.ok(cut < -4 && cut > -10, `signalled cut ${cut.toFixed(2)} dB`);
  const half = gainDb(new AacDecoder({ channels: 2, drc: { cut: 50 } }));
  assert.ok(Math.abs(half - cut / 2) < 0.5, `half cut ${half.toFixed(2)} dB`);
  const lifted = gainDb(new AacDecoder({ channels: 2, drc: { cut: 0, boost: 0, targetRefLevel: 100 } }));
  assert.ok(Math.abs(lifted - 6) < 0.3, `reference level gain ${lifted.toFixed(2)} dB`);
  assert.throws(() => new AacDecoder({ drc: { boost: 200 } }));
  assert.throws(() => new AacEncoder({ metadata: { drcProfile: 'loud' } }));

  const burner = new AacEncoder({ sampleRate, channels: 2, bitrate: 48000, afterburner: true });
  const burnt = Buffer.concat([burner.encode(pcm), burner.flush()]);
  const decoder = new AacDecoder({ channels: 2 });
  const out = decodeAll(decoder, burnt, burnt.length);
  assert.equal(decoder.errors, 0);
  assert.ok(snrDb(pcm, out, burner.delay * 2) > 20, 'afterburner stream decodes');
});

/* A USAC (xHE-AAC) LOAS stream configures the decoder from its
 * StreamMuxConfig and decodes frame by frame (the stream has 94 access
 * units of 1024 samples); the MPEG-D loudness metadata is read with it. */
test('decodes a USAC stream and reads its loudness', () => {
  const stream = readFileSync(new URL('../../tests/data/usac_music_48000_96000.loas', import.meta.url));
  const decoder = new AacDecoder({ channels: 0 });
  assert.equal(decoder.objectType, 'unknown');
  assert.deepEqual(decoder.loudness(), { programLoudness: null, normalizationGain: 0 });

  const first = decoder.decode(stream.subarray(0, 4000));
  assert.equal(decoder.objectType, 'usac');
  assert.equal(decoder.frameSize, 1024);
  assert.equal(decoder.channels, 2);
  assert.equal(decoder.streamChannels, 2);
  assert.equal(decoder.sampleRate, 48000);
  assert.equal(decoder.errors, 0);
  assert.equal(decoder.concealedFrames, 0, 'the frames decode');
  assert.ok(first.length > 0 && first.length % (1024 * 2) === 0, 'whole frames');
  assert.deepEqual(decoder.loudness(), { programLoudness: -14.25, normalizationGain: -9.75 });

  const rest = decodeAll(decoder, stream.subarray(4000), 4096);
  assert.equal(rest.length + first.length, 94 * 1024 * 2, 'every access unit decodes');
  assert.equal(decoder.concealedFrames, 0);
  assert.deepEqual(decoder.loudness(), { programLoudness: -14.25, normalizationGain: -9.75 });
  let peak = 0;
  for (const x of rest) {
    peak = Math.max(peak, Math.abs(x));
  }
  assert.ok(peak > 0.01 && peak <= 1, 'audio at a sane level');

  /* With concealment off the frames decode as well. */
  const strict = new AacDecoder({ channels: 0, concealment: 'off' });
  strict.decode(stream.subarray(0, 4000));
  assert.equal(strict.objectType, 'usac');
  assert.equal(strict.errors, 0);
  assert.equal(strict.loudness().programLoudness, -14.25);

  /* Loudness normalisation off, or a target equal to the program loudness,
   * applies no gain; the DRC request is accepted without changing it. */
  for (const options of [{ loudness: false }, { loudness: { targetLufs: -14.25 } }]) {
    const unity = new AacDecoder({ channels: 0, ...options });
    unity.decode(stream.subarray(0, 4000));
    assert.deepEqual(unity.loudness(), { programLoudness: -14.25, normalizationGain: 0 });
  }
  const requested = new AacDecoder({ channels: 0, drcEffect: 'night', albumMode: true, drcBoost: 50, drcCut: 50 });
  requested.decode(stream.subarray(0, 4000));
  assert.equal(requested.loudness().normalizationGain, -9.75);

  assert.deepEqual(new AacDecoder().loudness(), { programLoudness: null, normalizationGain: 0 });
  assert.throws(() => new AacDecoder({ drcEffect: 'loud' }), /drcEffect must be/);
  assert.throws(() => new AacDecoder({ drcBoost: 200 }));
  assert.throws(() => new AacDecoder({ drcCut: true }), /drcCut must be a number/);
  assert.throws(() => new AacDecoder({ loudness: { targetLufs: -5 } }));
  assert.throws(() => new AacDecoder({ loudness: { targetLufs: '-20' } }), /targetLufs must be a number/);
});
