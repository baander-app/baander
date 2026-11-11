/* Encode a synthetic signal, decode it, and check the reconstruction:
 * exercises both classes, chunked input, and the declared delay. */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { AacDecoder, AacEncoder, FlacDecoder, FlacEncoder, measureLoudness, version } from '../index.js';

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

test('reports the version of the package', () => {
  const pkg = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url), 'utf8'));
  assert.equal(pkg.version, '0.2.0');
  assert.equal(version, pkg.version);
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

test('decodes an MPEG Surround stream to 5.1', () => {
  const stream = readFileSync(new URL('../../tests/data/mps_5151_header_q.loas', import.meta.url));
  const decoder = new AacDecoder({ channels: 0 });
  const out = decodeAll(decoder, stream, 1000);
  assert.equal(decoder.errors, 0);
  assert.ok(decoder.hasSurround, 'surround decoded');
  assert.ok(!decoder.hasSbr && !decoder.hasPs);
  assert.equal(decoder.channels, 6);
  assert.equal(decoder.sampleRate, 48000);
  assert.equal(out.length, 96 * 1024 * 6, 'six channels per frame');
  const stereo = new AacDecoder({ channels: 2 });
  const mixed = decodeAll(stereo, stream, 1000);
  assert.equal(stereo.channels, 2);
  assert.equal(mixed.length, 96 * 1024 * 2, 'downmixed to the stereo request');
  const plain = new AacDecoder({ channels: 2 });
  decodeAll(plain, Buffer.concat([new AacEncoder({ sampleRate: 48000, channels: 2, bitrate: 128000, transport: 'loas' }).encode(tones(48000, 48000, 2))]), 1000);
  assert.ok(!plain.hasSurround, 'no surround in a plain stream');
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
  /* Damage the side information at the start of the fourth frame's raw
   * data block, where a decoder can detect it; damaged spectral data can
   * still parse, so a fixed byte offset depends on the encoder's choices. */
  let frame = 0;
  for (let k = 0; k < 3; k++) frame += ((stream[frame + 3] & 3) << 11) | (stream[frame + 4] << 3) | (stream[frame + 5] >> 5);
  const damaged = Buffer.from(stream);
  for (let i = frame + 7; i < frame + 27; i++) damaged[i] ^= 0x5a;

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

function adtsFrames(stream) {
  const frames = [];
  for (let pos = 0; pos + 7 <= stream.length;) {
    const length = ((stream[pos + 3] & 3) << 11) | (stream[pos + 4] << 3) | (stream[pos + 5] >> 5);
    frames.push(stream.subarray(pos, pos + length));
    pos += length;
  }
  return frames;
}

function concat(parts) {
  const out = new Float32Array(parts.reduce((n, d) => n + d.length, 0));
  let at = 0;
  for (const d of parts) {
    out.set(d, at);
    at += d.length;
  }
  return out;
}

test('drains the decoder delay at the end and seeks', () => {
  const sampleRate = 44100;
  const pcm = tones(sampleRate, sampleRate, 2);
  const encoder = new AacEncoder({ sampleRate, channels: 2, bitrate: 128000 });
  const stream = Buffer.concat([encoder.encode(pcm), encoder.flush()]);

  const plain = new AacDecoder({ channels: 2 });
  const plainOut = decodeAll(plain, stream, 1000);
  assert.equal(plain.delay, 0);
  assert.equal(plain.flush().length, 0, 'nothing held without the limiter');

  const limited = new AacDecoder({ channels: 2, limiter: { attackMs: 5, releaseMs: 50 } });
  const delay = limited.delay;
  assert.equal(delay, Math.floor(sampleRate * 5 / 1000));
  const body = decodeAll(limited, stream, 1000);
  const tail = limited.flush();
  assert.equal(tail.length, delay * 2, 'the look-ahead is drained');
  const all = concat([body, tail]);
  assert.equal(all.length, plainOut.length + delay * 2);
  let worst = 0;
  for (let i = 0; i < plainOut.length; i++) worst = Math.max(worst, Math.abs(all[i + delay * 2] - plainOut[i]));
  assert.ok(worst < 1e-6, `the limited output is the plain one delayed (${worst})`);
  assert.deepEqual(decodeAll(limited, stream, 1000), body, 'after flush the decoder starts over');

  const surround = new AacDecoder({ channels: 0 });
  decodeAll(surround, readFileSync(new URL('../../tests/data/mps_5151_header_q.loas', import.meta.url)), 1000);
  assert.equal(surround.delay, 1521);
  assert.equal(surround.flush().length, 1521 * 6, 'the surround delay is drained');
  assert.equal(surround.flush().length, 0, 'a second flush has nothing left');

  const frames = adtsFrames(stream);
  const seeking = new AacDecoder({ channels: 2 });
  decodeAll(seeking, Buffer.concat(frames.slice(0, 10)), 1000);
  seeking.decode(frames[10].subarray(0, frames[10].length >> 1));
  seeking.clearHistory();
  const fromSeek = decodeAll(seeking, Buffer.concat(frames.slice(25)), 1000);
  const fresh = decodeAll(new AacDecoder({ channels: 2 }), Buffer.concat(frames.slice(25)), 1000);
  assert.ok(fromSeek.length > 0);
  assert.deepEqual(fromSeek, fresh, 'after clearHistory the decode is a fresh decoder\'s');
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
      /metadata is not available for the ld, eld and xhe profiles/,
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
  assert.throws(
    () => new AacEncoder({ profile: 'xhe', usac: { segmentMaxBytes: 2 ** 32 } }),
    /segmentMaxBytes must be a number in the 32-bit integer range/,
  );
  assert.ok(new AacDecoder({ eldDownscale: 1, frameLength: 960, drc: { boost: 50 } }));
  // a peak below the default bitrate lowers a VBR mode instead of being refused
  assert.ok(new AacEncoder({ vbrMode: 3, peakBitrate: 96000 }));
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

/* The xHE-AAC encoder: a LOAS stream by default that the decoder reads
 * as USAC, the meter inside the encoder, the two-pass loudness info set
 * read back as the declared program loudness, and the standalone meter
 * on EBU Tech 3341's first case. */
test('encodes xHE-AAC with loudness metadata and meters the input', () => {
  const sampleRate = 48000;
  const channels = 2;
  const pcm = tones(188 * 1024, sampleRate, channels);
  const encoder = new AacEncoder({ sampleRate, channels, bitrate: 96000, profile: 'xhe', measureLoudness: true });
  assert.equal(encoder.profile, 'xhe');
  /* below 64 kbit/s per channel the encoder takes SBR: 2048-sample frames at the HE-AAC delay */
  assert.equal(encoder.frameSize, 2048);
  assert.equal(encoder.delay, 5635);
  assert.equal(encoder.loudness, null, 'no figures before any input');
  const stream = Buffer.concat([encoder.encode(pcm), encoder.flush()]);
  assert.equal(stream[0], 0x56, 'LOAS by default');
  const measured = encoder.loudness;
  assert.ok(measured && Number.isFinite(measured.integratedLkfs), 'the meter has figures after the stream');
  assert.ok(measured.rangeLu !== null && measured.maxShortTermLkfs !== null, 'four seconds give every figure');

  const plain = new AacDecoder({ channels: 0 });
  const out = decodeAll(plain, stream, 1000);
  assert.equal(plain.errors, 0);
  assert.equal(plain.objectType, 'usac');
  assert.equal(plain.streamChannels, 2);
  assert.equal(plain.sampleRate, sampleRate);
  assert.deepEqual(plain.loudness(), { programLoudness: null, normalizationGain: 0 }, 'no set written');
  const snr = snrDb(pcm, out, encoder.delay * channels);
  assert.ok(snr > 15, `xhe reconstruction SNR ${snr.toFixed(1)} dB`);

  /* Two-pass: the standalone meter reads as the encoder's on the same
   * frames, and its result goes into the next stream's configuration. */
  const info = measureLoudness(pcm, sampleRate, channels);
  assert.deepEqual(info, measured);
  const second = new AacEncoder({
    sampleRate, channels, bitrate: 96000, profile: 'xhe',
    loudnessInfo: info, usac: { streamId: 7, noiseFilling: false, sbr: 'off', mps212: 'off' },
  });
  assert.ok(second.config.length > 4, 'the USAC AudioSpecificConfig carries the set');
  const withSet = Buffer.concat([second.encode(pcm), second.flush()]);
  const decoder = new AacDecoder({ channels: 0 });
  decodeAll(decoder, withSet, 777);
  assert.equal(decoder.errors, 0);
  const declared = Math.round(info.integratedLkfs * 4) / 4;
  assert.deepEqual(decoder.loudness(), { programLoudness: declared, normalizationGain: -24 - declared });
  assert.equal(second.loudness, null, 'no meter without measureLoudness');

  /* EBU Tech 3341 case 1: a stereo 997 Hz sine at -23 dBFS reads -23 LUFS. */
  const sine = new Float32Array(sampleRate * 2 * channels);
  for (let i = 0; i < sampleRate * 2; i++) {
    const v = 10 ** (-23 / 20) * Math.sin((2 * Math.PI * 997 * i) / sampleRate);
    sine[i * channels] = v;
    sine[i * channels + 1] = v;
  }
  const ebu = measureLoudness(sine, sampleRate, channels);
  assert.ok(Math.abs(ebu.integratedLkfs + 23) < 0.1, `-23 dBFS sine reads ${ebu.integratedLkfs.toFixed(2)} LKFS`);
  assert.ok(Math.abs(ebu.truePeakDbtp + 23) < 0.1 && Math.abs(ebu.samplePeakDbfs + 23) < 0.05);
  assert.equal(ebu.maxShortTermLkfs, null, 'the short-term window needs 3 s');

  assert.throws(() => measureLoudness(new Float32Array(1000), sampleRate, channels), /400 ms/);
  assert.throws(() => measureLoudness(sine, sampleRate, 7), /1 to 6 channels/);
  assert.throws(() => measureLoudness(new Int16Array(10), sampleRate, channels), /Float32Array/);
  assert.throws(() => new AacEncoder({ profile: 'lc', usac: { streamId: 1 } }), /usac options need the xhe profile/);
  assert.throws(() => new AacEncoder({ profile: 'lc', loudnessInfo: info }), /loudnessInfo needs the xhe profile/);
  assert.throws(() => new AacEncoder({ profile: 'xhe', loudnessInfo: { integratedLkfs: -20 } }), /needs integratedLkfs and samplePeakDbfs/);
  assert.throws(() => new AacEncoder({ profile: 'xhe', loudnessInfo: { integratedLkfs: '-20', samplePeakDbfs: -1 } }), /must be a finite number/);
  assert.throws(() => new AacEncoder({ profile: 'xhe', usac: { sbr: 'maybe' } }), /on, off or auto/);
  assert.throws(() => new AacEncoder({ profile: 'xhe', usac: { streamId: 70000 } }), /streamId/);
  assert.throws(() => new AacEncoder({ profile: 'xhe', usac: { independentIntervalFrames: '5' } }), /must be a number/);
  assert.throws(() => new AacEncoder({ profile: 'xhe', transport: 'adts' }));
  assert.throws(() => new AacEncoder({ profile: 'xhe', metadata: { drcProfile: 'speech' } }), /not available for the ld, eld and xhe profiles/);
  assert.throws(() => new AacEncoder({ profile: 'xhe', afterburner: true }));
  assert.throws(() => new AacEncoder({ profile: 'xhe', channels: 6 }));
  assert.throws(() => new AacEncoder({ channels: 8, bitrate: 512000, measureLoudness: true }));
  assert.ok(new AacEncoder({ channels: 6, bitrate: 320000, measureLoudness: true }), 'the meter takes 5.1');
});

/* ── lossless mode ─────────────────────────────────────────────── */

/* Tones at a bit depth, interleaved, with a little noise. */
function intTones(samples, channels, bits, level = 0.5) {
  const pcm = new Int32Array(samples * channels);
  const peak = level * (2 ** (bits - 1) - 1);
  let seed = 12345;
  for (let i = 0; i < samples; i++) {
    for (let c = 0; c < channels; c++) {
      seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0;
      const noise = (seed / 2 ** 32 - 0.5) * 0.02;
      const v = 0.7 * Math.sin(i * (0.031 + 0.007 * c)) + 0.25 * Math.sin(i * (0.29 + 0.01 * c)) + noise;
      pcm[i * channels + c] = Math.round(Math.max(-1, Math.min(1, v)) * peak);
    }
  }
  return pcm;
}

/* Encodes in chunks of the given sample counts (cycled) and returns the
 * stream with the final header written over the first. */
function flacEncode(encoder, pcm, channels, chunks) {
  const parts = [];
  let pos = 0;
  for (let i = 0; pos < pcm.length; i++) {
    const count = Math.min(chunks[i % chunks.length] * channels, pcm.length - pos);
    parts.push(encoder.encode(pcm.subarray(pos, pos + count)));
    pos += count;
  }
  parts.push(encoder.finish());
  const stream = Buffer.concat(parts);
  const header = encoder.finalHeader();
  assert.equal(header.length, encoder.headerBytes);
  header.copy(stream, 0);
  return stream;
}

/* Decodes in chunks of the given byte counts (cycled), collecting what a
 * call held back for its bound before the next chunk goes in. */
function flacDecode(decoder, stream, chunks) {
  const parts = [];
  let pos = 0;
  for (let i = 0; pos < stream.length; i++) {
    const count = Math.min(chunks[i % chunks.length], stream.length - pos);
    parts.push(decoder.decode(stream.subarray(pos, pos + count)));
    while (decoder.pending) {
      parts.push(decoder.decode());
    }
    pos += count;
  }
  do {
    parts.push(decoder.finish());
  } while (decoder.pending);
  const out = new Int32Array(parts.reduce((n, p) => n + p.length, 0));
  let at = 0;
  for (const p of parts) {
    out.set(p, at);
    at += p.length;
  }
  return out;
}

test('FLAC: a stereo 16-bit signal in uneven chunks comes back bit for bit', () => {
  const sampleRate = 44100;
  const channels = 2;
  const samples = 50000;
  const pcm = intTones(samples, channels, 16);
  const encoder = new FlacEncoder({ sampleRate, channels, bitsPerSample: 16, totalSamples: samples, seekTable: true });
  assert.equal(encoder.blockSize, 4096);
  assert.equal(encoder.level, 5);
  assert.equal(encoder.lastFrame, null);
  const stream = flacEncode(encoder, pcm, channels, [1, 4095, 4097, 777, 12000]);
  assert.equal(stream.subarray(0, 4).toString('latin1'), 'fLaC');
  assert.equal(encoder.streamInfo.totalSamples, samples);
  assert.equal(encoder.lastFrame.samplePosition + encoder.lastFrame.blockSize, samples);

  const decoder = new FlacDecoder({ md5: true });
  assert.equal(decoder.streamInfo, null);
  assert.equal(decoder.sampleRate, 0);
  const out = flacDecode(decoder, stream, [1, 2, 3, 4000, 17, 30000]);
  assert.deepEqual(out, pcm);
  assert.equal(decoder.md5, 'match');
  assert.equal(decoder.errors, 0);
  assert.equal(decoder.sampleRate, sampleRate);
  assert.equal(decoder.channels, channels);
  assert.equal(decoder.bitsPerSample, 16);
  assert.equal(decoder.streamInfo.totalSamples, samples);
  assert.deepEqual(decoder.streamInfo.md5, encoder.streamInfo.md5);
  assert.equal(decoder.firstFrameOffset, encoder.headerBytes);
  assert.ok(decoder.seekPoints.length >= 1);
  assert.equal(decoder.seekPoints[0].samplePosition, 0);
  assert.equal(decoder.lastFrame.samplePosition + decoder.lastFrame.blockSize, samples);
  assert.throws(() => decoder.decode(stream), /finished/);

  // without the final header the stream decodes all the same, with no MD5 to compare
  const again = new FlacEncoder({ sampleRate, channels });
  const piped = Buffer.concat([again.encode(pcm), again.finish()]);
  const plain = new FlacDecoder({ md5: true });
  assert.deepEqual(flacDecode(plain, piped, [5000]), pcm);
  assert.equal(plain.md5, 'unknown');
  assert.equal(plain.streamInfo.totalSamples, 0);

  // the encoder starts a second stream after the first
  const second = Buffer.concat([again.encode(pcm.subarray(0, 2000)), again.finish()]);
  plain.reset();
  assert.deepEqual(flacDecode(plain, second, [333]), pcm.subarray(0, 2000));
});

test('FLAC: 24-bit 5.1 and every level round-trip', () => {
  const pcm = intTones(9000, 6, 24);
  for (const level of [0, 3, 5, 8]) {
    const encoder = new FlacEncoder({ sampleRate: 48000, channels: 6, bitsPerSample: 24, level });
    const stream = flacEncode(encoder, pcm, 6, [2500]);
    const decoder = new FlacDecoder({ md5: true });
    assert.deepEqual(flacDecode(decoder, stream, [4096]), pcm, `level ${level}`);
    assert.equal(decoder.md5, 'match');
    assert.equal(decoder.channels, 6);
    assert.equal(decoder.bitsPerSample, 24);
  }
});

test('FLAC: 32-bit samples at the extremes of the range round-trip', () => {
  const samples = 10000;
  const pcm = new Int32Array(samples * 2);
  for (let i = 0; i < samples; i++) {
    // full-scale extremes against each other, so that the side channel needs 33 bits
    pcm[i * 2] = i % 3 === 0 ? -2147483648 : 2147483647;
    pcm[i * 2 + 1] = i % 2 === 0 ? 2147483647 : -2147483648;
  }
  const smooth = intTones(samples, 2, 32, 0.9);
  for (const input of [pcm, smooth]) {
    for (const level of [0, 5, 8]) {
      const encoder = new FlacEncoder({ sampleRate: 96000, channels: 2, bitsPerSample: 32, level });
      const stream = flacEncode(encoder, input, 2, [4096, 1]);
      const decoder = new FlacDecoder({ md5: true });
      assert.deepEqual(flacDecode(decoder, stream, [10000]), input, `level ${level}`);
      assert.equal(decoder.md5, 'match');
    }
  }
});

test('FLAC: a damaged frame is reported and decoding continues', () => {
  const samples = 4096 * 6;
  const pcm = intTones(samples, 2, 16);
  const encoder = new FlacEncoder({ sampleRate: 44100, channels: 2 });
  const stream = flacEncode(encoder, pcm, 2, [4096]);
  const damaged = Buffer.from(stream);
  damaged[Math.floor(stream.length / 2)] ^= 0x10;
  const decoder = new FlacDecoder({ md5: true });
  const out = flacDecode(decoder, damaged, [3000]);
  assert.equal(decoder.errors, 1);
  assert.equal(out.length, (samples - 4096) * 2);
  assert.equal(decoder.md5, 'mismatch');
  // the frames before and after the damaged one are the input's
  assert.deepEqual(out.subarray(0, 4096 * 2), pcm.subarray(0, 4096 * 2));
  assert.deepEqual(out.subarray(out.length - 4096 * 2), pcm.subarray(pcm.length - 4096 * 2));
  assert.equal(decoder.lastFrame.samplePosition, samples - 4096);

  // a stream that is cut short is one more damaged stretch
  const cut = new FlacDecoder();
  flacDecode(cut, stream.subarray(0, stream.length - 100), [5000]);
  assert.equal(cut.errors, 1);
});

test('FLAC: decodes frames with STREAMINFO out of band', () => {
  const pcm = intTones(10000, 2, 16);
  const encoder = new FlacEncoder({ sampleRate: 44100, channels: 2 });
  const stream = flacEncode(encoder, pcm, 2, [10000]);
  const streamInfo = stream.subarray(8, 8 + 34);
  const decoder = new FlacDecoder({ streamInfo, md5: true });
  assert.equal(decoder.streamInfo.totalSamples, 10000);
  assert.deepEqual(flacDecode(decoder, stream.subarray(encoder.headerBytes), [4096]), pcm);
  assert.equal(decoder.md5, 'match');
  assert.throws(() => new FlacDecoder({ streamInfo: Buffer.alloc(33) }), /STREAMINFO/);
  assert.throws(() => new FlacDecoder({ streamInfo: 'no' }), /streamInfo must be a Buffer/);
});

test('FLAC: refuses options of the wrong kind and what FLAC cannot hold', () => {
  assert.throws(() => new FlacEncoder({ sampleRate: '44100' }), /sampleRate must be a number/);
  assert.throws(() => new FlacEncoder({ channels: true }), /channels must be a number/);
  assert.throws(() => new FlacEncoder({ bitsPerSample: {} }), /bitsPerSample must be a number/);
  assert.throws(() => new FlacEncoder({ level: '5' }), /level must be a number/);
  assert.throws(() => new FlacEncoder({ padding: null }), /padding must be a number/);
  assert.throws(() => new FlacEncoder({ totalSamples: -1 }), /totalSamples must be a whole number/);
  assert.throws(() => new FlacEncoder({ totalSamples: 1.5 }), /totalSamples must be a whole number/);
  assert.throws(() => new FlacEncoder({ seekTable: 1 }), /seekTable must be a boolean/);
  assert.throws(() => new FlacDecoder({ md5: 'yes' }), /md5 must be a boolean/);
  assert.throws(() => new FlacEncoder({ channels: 9 }), /unsupported configuration/);
  assert.throws(() => new FlacEncoder({ channels: 0 }), /unsupported configuration/);
  assert.throws(() => new FlacEncoder({ bitsPerSample: 17 }), /unsupported configuration/);
  assert.throws(() => new FlacEncoder({ level: 9 }), /unsupported configuration/);
  assert.throws(() => new FlacEncoder({ sampleRate: 700001 }), /unsupported configuration/);
  assert.throws(() => new FlacEncoder({ sampleRate: 0 }), /unsupported configuration/);

  const encoder = new FlacEncoder({ sampleRate: 48000, channels: 2, bitsPerSample: 16 });
  assert.throws(() => encoder.encode(new Float32Array(8)), /expected an Int32Array/);
  assert.throws(() => encoder.encode(new Int32Array(3)), /multiple of the channel count/);
  assert.throws(() => encoder.finalHeader(), /not finished/);
  // a sample outside the depth refuses the whole call: nothing of it is coded
  const bad = new Int32Array(20000);
  bad[19999] = 32768;
  assert.throws(() => encoder.encode(bad), /outside the bit depth/);
  const good = intTones(5000, 2, 16);
  const stream = Buffer.concat([encoder.encode(good), encoder.finish()]);
  const decoder = new FlacDecoder();
  assert.deepEqual(flacDecode(decoder, stream, [4096]), good);
  assert.throws(() => new FlacDecoder().decode('text'), /expected a Buffer/);
});

test('FLAC: tags and pictures are written and read back', () => {
  const pcm = intTones(10000, 2, 16);
  const tags = ['ARTIST=Ravel', 'GENRE=Classical', 'GENRE=Orchestral', 'TITLE=Boléro – 🎻', 'COMMENT='];
  const cover = Buffer.from(Array.from({ length: 5000 }, (_, i) => (i * 37) & 255));
  const icon = new Uint8Array([1, 2, 3, 4, 5]);
  const encoder = new FlacEncoder({
    sampleRate: 44100,
    channels: 2,
    tags,
    vendor: 'smoke test ♫',
    pictures: [
      { mimeType: 'image/jpeg', description: 'Front – é', width: 600, height: 400, depth: 24, data: cover },
      { type: 1, mimeType: 'image/png', width: 32, height: 32, depth: 8, colors: 16, data: icon },
    ],
  });
  const stream = flacEncode(encoder, pcm, 2, [3000]);

  const decoder = new FlacDecoder({ md5: true, metadata: true });
  assert.deepEqual(decoder.tags, []);
  assert.deepEqual(flacDecode(decoder, stream, [1000]), pcm);
  assert.equal(decoder.md5, 'match');
  assert.deepEqual(decoder.tags, tags);
  assert.equal(decoder.vendor, 'smoke test ♫');
  assert.equal(decoder.skippedMetadata, 0);
  const pictures = decoder.pictures;
  assert.equal(pictures.length, 2);
  assert.deepEqual(
    { ...pictures[0], data: null },
    { type: 3, mimeType: 'image/jpeg', description: 'Front – é', width: 600, height: 400, depth: 24, colors: 0, data: null },
  );
  assert.ok(pictures[0].data.equals(cover));
  assert.deepEqual(
    { ...pictures[1], data: null },
    { type: 1, mimeType: 'image/png', description: '', width: 32, height: 32, depth: 8, colors: 16, data: null },
  );
  assert.ok(pictures[1].data.equals(Buffer.from(icon)));

  // the frames are those of a stream without tags
  const plainEncoder = new FlacEncoder({ sampleRate: 44100, channels: 2 });
  const plain = flacEncode(plainEncoder, pcm, 2, [3000]);
  assert.ok(stream.subarray(encoder.headerBytes).equals(plain.subarray(plainEncoder.headerBytes)));

  // a decoder that was not asked keeps nothing; one with a small limit skips the cover
  const quiet = new FlacDecoder();
  assert.deepEqual(flacDecode(quiet, stream, [4096]), pcm);
  assert.deepEqual(quiet.tags, []);
  assert.equal(quiet.vendor, '');
  assert.deepEqual(quiet.pictures, []);
  const limited = new FlacDecoder({ metadata: { maxBytes: 1000 } });
  assert.deepEqual(flacDecode(limited, stream, [4096]), pcm);
  assert.deepEqual(limited.tags, tags);
  assert.equal(limited.skippedMetadata, 1);
  assert.equal(limited.pictures.length, 1);
  assert.equal(limited.pictures[0].type, 1);

  // what the format does not take, and options of the wrong kind
  assert.throws(() => new FlacEncoder({ tags: ['NOEQUALS'] }), /the format does not take/);
  assert.throws(() => new FlacEncoder({ tags: ['NÄME=x'] }), /the format does not take/);
  assert.throws(() => new FlacEncoder({ tags: ['=x'] }), /the format does not take/);
  assert.throws(() => new FlacEncoder({ tags: 'ARTIST=x' }), /tags must be an array/);
  assert.throws(() => new FlacEncoder({ tags: [1] }), /tags must be an array/);
  assert.throws(() => new FlacEncoder({ vendor: 5 }), /vendor must be a string/);
  assert.throws(() => new FlacEncoder({ pictures: {} }), /pictures must be an array/);
  assert.throws(() => new FlacEncoder({ pictures: [{ data: cover }] }), /mimeType must be a string/);
  assert.throws(() => new FlacEncoder({ pictures: [{ mimeType: 'image/png' }] }), /data must be a Buffer/);
  assert.throws(
    () => new FlacEncoder({ pictures: [{ mimeType: 'image/png', data: cover, width: '600' }] }),
    /width must be a whole number/,
  );
  assert.throws(
    () => new FlacEncoder({ pictures: [{ mimeType: 'image/png', data: cover, type: 21 }] }),
    /the format does not take/,
  );
  const icons = [1, 1].map((type) => ({ type, mimeType: 'image/png', data: icon }));
  assert.throws(() => new FlacEncoder({ pictures: icons }), /the format does not take/);
  assert.throws(() => new FlacDecoder({ metadata: 'yes' }), /metadata must be a boolean or an object/);
  assert.throws(() => new FlacDecoder({ metadata: { maxBytes: -1 } }), /maxBytes must be a whole number/);
});

test('FLAC: a picture buffer detached or shrunk while the options are read is never read stale', () => {
  const detach = (buffer) => structuredClone(buffer, { transfer: [buffer] });
  // a getter of the picture itself detaches the picture's bytes
  const first = new Uint8Array(new ArrayBuffer(200000)).fill(0x5a);
  const picture = {
    mimeType: 'image/png',
    data: first,
    get width() {
      detach(first.buffer);
      return 32;
    },
  };
  assert.throws(() => new FlacEncoder({ pictures: [picture] }), /detached/);
  // an option of the encoder read after the pictures does
  const second = new Uint8Array(new ArrayBuffer(200000)).fill(0x5a);
  const options = { pictures: [{ mimeType: 'image/png', data: second }] };
  for (const key of ['sampleRate', 'channels', 'bitsPerSample', 'level', 'padding', 'totalSamples', 'seekTable', 'tags', 'vendor']) {
    Object.defineProperty(options, key, {
      enumerable: true,
      get() {
        if (second.buffer.byteLength) {
          detach(second.buffer);
        }
        return undefined;
      },
    });
  }
  assert.throws(() => new FlacEncoder(options), /detached/);
  // a later picture's getter detaches an earlier picture's bytes
  const third = new Uint8Array(new ArrayBuffer(200000)).fill(0x5a);
  const later = {
    mimeType: 'image/png',
    data: new Uint8Array(4),
    get description() {
      detach(third.buffer);
      return 'x';
    },
  };
  assert.throws(() => new FlacEncoder({ pictures: [{ mimeType: 'image/png', data: third }, later] }), /detached/);

  // a buffer that shrinks while the options are read gives the picture its length at the end
  const resizable = new ArrayBuffer(5000, { maxByteLength: 5000 });
  const view = new Uint8Array(resizable).fill(7);
  const shrinking = {
    mimeType: 'image/png',
    data: view,
    get height() {
      resizable.resize(10);
      return 1;
    },
  };
  const encoder = new FlacEncoder({ pictures: [shrinking] });
  const stream = Buffer.concat([encoder.encode(intTones(100, 2, 16)), encoder.finish()]);
  const decoder = new FlacDecoder({ metadata: true });
  flacDecode(decoder, stream, [4096]);
  assert.equal(decoder.pictures.length, 1);
  assert.ok(decoder.pictures[0].data.equals(Buffer.alloc(10, 7)));
});

/* A stream at the format's worst expansion: frames of 65535 samples in
 * eight channels at 32 bits, each channel a constant: 50 bytes that
 * decode to two megabytes. */
function largestBlockStream(frames) {
  const crc = (bytes, bits, poly) => {
    const top = 1 << (bits - 1);
    const mask = 2 ** bits - 1;
    let c = 0;
    for (const b of bytes) {
      c ^= b << (bits - 8);
      for (let i = 0; i < 8; i++) {
        c = c & top ? ((c << 1) ^ poly) & mask : (c << 1) & mask;
      }
    }
    return c;
  };
  const parts = [Buffer.from('fLaC'), Buffer.from([0x80, 0, 0, 34])];
  // STREAMINFO: blocks of 65535, 44100 Hz, 8 channels, 32 bits, no total, no MD5
  const info = Buffer.alloc(34);
  info.writeUInt16BE(65535, 0);
  info.writeUInt16BE(65535, 2);
  info.set([0x0a, 0xc4, 0x4f, 0xf0], 10);
  parts.push(info);
  for (let f = 0; f < frames; f++) {
    // sync, a block size given in 16 bits and 44.1 kHz, 8 channels and 32 bits, the frame number
    const header = [0xff, 0xf8, 0x79, 0x7e, f, 0xff, 0xfe];
    header.push(crc(header, 8, 0x07));
    const frame = [...header];
    for (let c = 0; c < 8; c++) {
      const value = (c * 1000003 + f) | 0;
      frame.push(0x00, (value >>> 24) & 255, (value >>> 16) & 255, (value >>> 8) & 255, value & 255);
    }
    const sum = crc(frame, 16, 0x8005);
    frame.push(sum >> 8, sum & 255);
    parts.push(Buffer.from(frame));
  }
  return Buffer.concat(parts);
}

test('FLAC: a decode call returns no more than its bound, and the rest on the calls after', () => {
  const frames = 24;
  const frameValues = 65535 * 8;
  const stream = largestBlockStream(frames);
  assert.ok(stream.length < 1300); // for 24 * 2 MiB of samples

  const check = (decoder, bound) => {
    let total = 0;
    let calls = 0;
    const take = (out) => {
      assert.ok(out.length <= bound, `${out.length} values from one call, the bound is ${bound}`);
      for (let at = 0; at < out.length; at += frameValues) {
        const frame = (total + at) / frameValues;
        // the first sample of each channel, and the last of the frame
        for (let c = 0; c < 8; c++) {
          assert.equal(out[at + c], (c * 1000003 + frame) | 0);
        }
        assert.equal(out[at + frameValues - 1], (7 * 1000003 + frame) | 0);
      }
      total += out.length;
      calls++;
    };
    take(decoder.decode(stream));
    assert.equal(decoder.pending, true);
    // more input is refused while output is waiting: nothing piles up
    assert.throws(() => decoder.decode(stream), /pending/);
    while (decoder.pending) {
      take(decoder.decode());
    }
    do {
      take(decoder.finish());
    } while (decoder.pending);
    assert.equal(total, frames * frameValues);
    assert.equal(decoder.errors, 0);
    assert.equal(decoder.pending, false);
    return calls;
  };
  // the default: 1048576 values a call, two of these frames
  const byDefault = new FlacDecoder();
  assert.equal(byDefault.maxOutput, 1048576);
  assert.ok(check(byDefault, 1048576) >= frames / 2);
  // the smallest bound there is: one frame of the largest size
  const smallest = new FlacDecoder({ maxOutput: frameValues });
  assert.ok(check(smallest, frameValues) >= frames);
  assert.throws(() => new FlacDecoder({ maxOutput: frameValues - 1 }), /maxOutput/);
  assert.throws(() => new FlacDecoder({ maxOutput: '1000000' }), /maxOutput must be a whole number/);

  // a finish that meets the bound goes on the same way
  const atOnce = new FlacDecoder({ maxOutput: frameValues });
  const first = atOnce.decode(stream.subarray(0, 42 + 50 * 3 + 7));
  assert.equal(first.length, frameValues);
  let rest = 0;
  while (atOnce.pending) {
    rest += atOnce.decode().length;
  }
  assert.equal(rest, 2 * frameValues);
  assert.equal(atOnce.finish().length, 0); // the seven bytes of a fourth frame are a frame cut short
  assert.equal(atOnce.errors, 1);
  assert.throws(() => atOnce.decode(stream), /finished/);
});

test('FLAC: finish() ends one stream once, also a stream of no samples', () => {
  // no samples: the header of an empty stream, which decodes to nothing
  const empty = new FlacEncoder({ sampleRate: 44100, channels: 2 });
  const stream = empty.finish();
  assert.equal(stream.length, empty.headerBytes);
  assert.equal(stream.subarray(0, 4).toString('latin1'), 'fLaC');
  const decoder = new FlacDecoder({ md5: true });
  assert.equal(flacDecode(decoder, stream, [stream.length]).length, 0);
  assert.equal(decoder.errors, 0);
  assert.equal(empty.finalHeader().length, empty.headerBytes);
  // a second finish() has no stream to end
  assert.throws(() => empty.finish(), /the stream is finished; encode\(\) starts another/);
  assert.throws(() => empty.finish(), /the stream is finished/);

  // the same after samples, and encode() then starts a stream of its own
  const pcm = intTones(5000, 2, 16);
  const encoder = new FlacEncoder({ sampleRate: 44100, channels: 2 });
  const first = Buffer.concat([encoder.encode(pcm), encoder.finish()]);
  assert.throws(() => encoder.finish(), /the stream is finished/);
  const second = Buffer.concat([encoder.encode(pcm), encoder.finish()]);
  assert.deepEqual(second, first);
  assert.deepEqual(flacDecode(new FlacDecoder(), second, [3000]), pcm);
});

test('FLAC: an error that ends a stream leaves nothing pending', () => {
  // a stereo stream, and behind it frames of a mono stream: a change of
  // format in the middle, which the decoder does not follow
  const stereo = intTones(4096 * 3, 2, 16);
  const mono = intTones(4096 * 3, 1, 16);
  const a = new FlacEncoder({ sampleRate: 44100, channels: 2 });
  const b = new FlacEncoder({ sampleRate: 44100, channels: 1 });
  const first = flacEncode(a, stereo, 2, [4096]);
  const second = flacEncode(b, mono, 1, [4096]);
  const joined = Buffer.concat([first, second.subarray(b.headerBytes), Buffer.alloc(5000, 0x55)]);

  const decoder = new FlacDecoder();
  // the samples before the change come out first, with the error waiting
  const out = decoder.decode(joined);
  assert.deepEqual(out, stereo);
  assert.equal(decoder.pending, true);
  assert.throws(() => decoder.decode(), /unsupported stream/);
  // the error has been thrown: nothing waits, and a drain loop ends
  assert.equal(decoder.pending, false);
  // the stream stays where it is: with or without bytes, the same answer
  assert.throws(() => decoder.decode(), /unsupported stream; the stream cannot be decoded further/);
  assert.throws(() => decoder.decode(Buffer.alloc(100)), /cannot be decoded further/);
  assert.equal(decoder.pending, false);
  // finish() closes it without samples and without a verdict
  assert.equal(decoder.finish().length, 0);
  assert.equal(decoder.pending, false);
  assert.equal(decoder.md5, 'unknown');
  assert.throws(() => decoder.decode(), /the stream is finished/);
  // reset() starts over
  decoder.reset();
  assert.equal(decoder.pending, false);
  assert.deepEqual(flacDecode(decoder, first, [5000]), stereo);

  // a seek leaves the error behind as well
  const seeker = new FlacDecoder();
  seeker.decode(joined);
  assert.throws(() => seeker.decode(), /unsupported stream/);
  seeker.seekReset();
  assert.deepEqual(seeker.decode(first.subarray(a.headerBytes)), stereo);
});

test('FLAC: a stream cut short is counted, and a seek after finish() takes bytes again', () => {
  const pcm = intTones(4096 * 4, 2, 16);
  const encoder = new FlacEncoder({ sampleRate: 44100, channels: 2 });
  const stream = flacEncode(encoder, pcm, 2, [4096]);
  // the input ends inside the last frame: finish() does not throw, and
  // `errors` has the damage
  const cut = new FlacDecoder({ md5: true });
  const got = cut.decode(stream.subarray(0, stream.length - 50));
  const rest = cut.finish();
  assert.equal(got.length + rest.length, 4096 * 3 * 2);
  assert.equal(cut.errors, 1);
  assert.equal(cut.pending, false);

  // finish() was called while samples were still held back by the
  // bound; a seek then starts input again
  const long = intTones(1200000, 2, 16, 0.01);
  const longEncoder = new FlacEncoder({ sampleRate: 44100, channels: 2, level: 0 });
  const longStream = flacEncode(longEncoder, long, 2, [200000]);
  const decoder = new FlacDecoder();
  const head = decoder.decode(longStream);
  assert.ok(head.length > 0 && head.length <= decoder.maxOutput);
  assert.equal(decoder.pending, true);
  const more = decoder.finish();
  assert.ok(more.length > 0 && head.length + more.length < long.length);
  assert.equal(decoder.pending, true);
  assert.throws(() => decoder.decode(Buffer.alloc(10)), /the stream is finished/);
  decoder.seekReset();
  assert.equal(decoder.pending, false);
  const again = decoder.decode(longStream.subarray(longEncoder.headerBytes));
  assert.equal(again.length, head.length);
  assert.ok(again.every((v, i) => v === long[i]));
});

test('FLAC: ID3 tags around a stream are passed over and reported', () => {
  const pcm = intTones(4096 * 2 + 300, 2, 16);
  const encoder = new FlacEncoder({ sampleRate: 44100, channels: 2 });
  const stream = flacEncode(encoder, pcm, 2, [5000]);
  // an ID3v2 tag: 'ID3', version, flags, and its length in four 7-bit bytes
  const body = 2000;
  const front = Buffer.concat([
    Buffer.from([0x49, 0x44, 0x33, 4, 0, 0, (body >> 21) & 0x7f, (body >> 14) & 0x7f, (body >> 7) & 0x7f, body & 0x7f]),
    Buffer.alloc(body, 0x41),
  ]);
  // an ID3v1 tag: 128 bytes that begin with 'TAG'
  const behind = Buffer.alloc(128, 0);
  behind.write('TAGTitle', 0, 'latin1');

  const plain = new FlacDecoder({ md5: true });
  assert.deepEqual(flacDecode(plain, stream, [3000]), pcm);
  assert.equal(plain.id3v2Bytes, 0);
  assert.equal(plain.id3v1, false);

  const both = new FlacDecoder({ md5: true });
  assert.equal(both.id3v2Bytes, 0);
  assert.deepEqual(flacDecode(both, Buffer.concat([front, stream, behind]), [3000]), pcm);
  assert.equal(both.errors, 0);
  assert.equal(both.md5, 'match');
  assert.equal(both.id3v2Bytes, front.length);
  assert.equal(both.id3v1, true);
  assert.equal(both.firstFrameOffset, front.length + encoder.headerBytes);
  both.reset();
  assert.equal(both.id3v2Bytes, 0);
  assert.equal(both.id3v1, false);

  // 128 bytes behind the last frame that are no ID3v1 tag are damage
  const other = new FlacDecoder();
  flacDecode(other, Buffer.concat([stream, Buffer.alloc(128, 0x55)]), [3000]);
  assert.equal(other.id3v1, false);
  assert.equal(other.errors, 1);
});

test('FLAC: bytes over a SharedArrayBuffer are decoded or refused, never read from nowhere', () => {
  const pcm = intTones(5000, 2, 16);
  const encoder = new FlacEncoder({ sampleRate: 44100, channels: 2 });
  const stream = flacEncode(encoder, pcm, 2, [5000]);
  const shared = new Uint8Array(new SharedArrayBuffer(stream.length));
  shared.set(stream);
  const decoder = new FlacDecoder();
  let out = null;
  let refused = null;
  try {
    out = decoder.decode(shared);
  } catch (error) {
    refused = error;
  }
  if (refused) {
    // this runtime does not hand the bytes of a shared buffer to a binding
    assert.ok(refused instanceof Error);
    assert.equal(decoder.pending, false);
  } else {
    assert.deepEqual(Int32Array.from([...out, ...decoder.finish()]), pcm);
  }
  // the encoder's picture data takes the same road
  const picture = { mimeType: 'image/png', data: new Uint8Array(new SharedArrayBuffer(64)) };
  try {
    new FlacEncoder({ pictures: [picture] }).finish();
  } catch (error) {
    assert.ok(error instanceof Error);
  }
});

/* The signal of the codec's own digest test (flac_gen_integer in
 * tests/test_flac_util.h), made with integers alone: two triangle waves
 * a channel and a little noise. BigInt keeps the divisions exact. */
function integerSignal(samples, channels, bits, seed) {
  const pcm = new Int32Array(samples * channels);
  const peak = (1n << BigInt(bits - 1)) - 1n;
  const triangle = (i, period) => {
    const phase = i % (2n * period);
    return 2n * (phase < period ? phase : 2n * period - phase) - period;
  };
  let state = seed >>> 0;
  for (let i = 0; i < samples; i++) {
    for (let c = 0; c < channels; c++) {
      state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
      const slow = BigInt(97 + 31 * c);
      const fast = BigInt(23 + 7 * c);
      const v =
        (triangle(BigInt(i), slow) * ((peak * 3n) / 8n)) / slow +
        (triangle(BigInt(i), fast) * (peak / 8n)) / fast +
        ((BigInt(state >>> 16) - 32768n) * (peak / 64n)) / 32768n;
      pcm[i * channels + c] = Number(v);
    }
  }
  return pcm;
}

test('FLAC: the encoder writes the pinned streams on this platform', () => {
  // the digests tests/test_flac_encoder.cpp pins for the same inputs:
  // the binding of every platform has to write these bytes
  const pinned = [
    {
      sampleRate: 44100, channels: 2, bitsPerSample: 16, samples: 20000, seed: 1,
      digests: {
        0: '62e623de5614b170cd637d31387699ef9d3c3f88c92d60731e6ebf42a028d3f0',
        5: 'f8826ec09138e2545a4cb6759a4d6ccfcd90c5cfa93134a5314e8d00fdf5de02',
        8: 'b61d73372fd7caee7251f476e3cf807cab5df409826df03ba927eddc9609d45d',
      },
    },
    {
      sampleRate: 96000, channels: 2, bitsPerSample: 24, samples: 12000, seed: 2,
      digests: {
        0: '2fa0c50aba068607244450fc721d99070d440469f6a60655ccbed5862f70574f',
        5: 'ac5e520aa11524a7f8f840e217cde775ecf59341db9b8b1acd27645961e33510',
        8: '66fb30848692b13c9fb05e274b21cab3719165bdc5d6a001be714bdb43f1c48f',
      },
    },
  ];
  for (const p of pinned) {
    const pcm = integerSignal(p.samples, p.channels, p.bitsPerSample, p.seed);
    for (const [level, digest] of Object.entries(p.digests)) {
      const encoder = new FlacEncoder({
        sampleRate: p.sampleRate, channels: p.channels, bitsPerSample: p.bitsPerSample, level: Number(level),
      });
      const stream = flacEncode(encoder, pcm, p.channels, [p.samples]);
      assert.equal(createHash('sha256').update(stream).digest('hex'), digest, `${p.sampleRate} Hz, ${p.bitsPerSample} bits, level ${level}`);
    }
  }
});

test('an option whose getter throws surfaces that error', () => {
  const thrower = (key) => ({
    get [key]() {
      throw new RangeError(`getter of ${key}`);
    },
  });
  for (const key of ['sampleRate', 'level', 'totalSamples', 'seekTable', 'tags', 'vendor', 'pictures']) {
    assert.throws(() => new FlacEncoder(thrower(key)), new RegExp(`getter of ${key}`));
  }
  assert.throws(
    () => new FlacEncoder({ pictures: [Object.assign(thrower('data'), { mimeType: 'image/png' })] }),
    /getter of data/,
  );
  assert.throws(
    () => new FlacEncoder({ pictures: [Object.assign(thrower('width'), { mimeType: 'image/png', data: new Uint8Array(4) })] }),
    /getter of width/,
  );
  for (const key of ['md5', 'metadata', 'maxOutput', 'streamInfo']) {
    assert.throws(() => new FlacDecoder(thrower(key)), new RegExp(`getter of ${key}`));
  }
  assert.throws(() => new FlacDecoder({ metadata: thrower('maxBytes') }), /getter of maxBytes/);
  // the AAC classes read their numeric options through the same helper
  assert.throws(() => new AacDecoder(thrower('frameLength')), /getter of frameLength/);
  assert.throws(() => new AacEncoder(thrower('frameLength')), /getter of frameLength/);
});
