import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';

// The binding comes from node_modules when installed, else from the source
// package when it has been built there (npm install in the codec directory).
const codec = resolve(dirname(fileURLToPath(import.meta.url)), '../../../packages/codecs/baander-aac');
if (!process.env.BAANDER_AAC_NATIVE_MODULE && existsSync(join(codec, 'build/Release/baander_aac.node'))) {
  process.env.BAANDER_AAC_NATIVE_MODULE = pathToFileURL(join(codec, 'node/index.js')).href;
}
const { AacService, loadAacNative } = await import('../src/main/services/aac.service.ts');
const available = (await loadAacNative()).native !== null;

function tones(samples: number, sampleRate: number, channels: number): Float32Array {
  const pcm = new Float32Array(samples * channels);
  for (let i = 0; i < samples; i++) {
    for (let c = 0; c < channels; c++) {
      pcm[i * channels + c] = 0.3 * Math.sin((2 * Math.PI * (c ? 660 : 440) * i) / sampleRate);
    }
  }
  return pcm;
}

function snrDb(ref: Float32Array, out: Float32Array, offset: number): number {
  let sig = 0;
  let err = 0;
  const n = Math.min(ref.length, out.length - offset);
  for (let i = 0; i < n; i++) {
    sig += ref[i] * ref[i];
    err += (ref[i] - out[i + offset]) ** 2;
  }
  return 10 * Math.log10(sig / Math.max(err, 1e-30));
}

test('info reports the binding state and the service never throws on info()', async () => {
  const info = await new AacService().info();
  assert.equal(info.available, available);
  assert.equal(typeof info.version === 'string' || info.version === null, true);
});

test('one-shot encode/decode reconstructs the signal', { skip: !available && 'native binding not built' }, async () => {
  const service = new AacService();
  const pcm = tones(44100, 44100, 2);
  const stream = await service.encode(pcm, { sampleRate: 44100, channels: 2, bitrate: 128000, mode: 'cbr' });
  assert.ok(stream.length > 1000);
  const result = await service.decode(stream, { sampleRate: 44100, channels: 2 });
  assert.equal(result.errors, 0);
  assert.equal(result.sampleRate, 44100);
  assert.equal(result.channels, 2);
  assert.equal(result.streamChannels, 2);
  assert.equal(result.hasSbr, false);
  assert.equal(result.hasPs, false);
  assert.equal(result.objectType, 'lc');
  assert.deepEqual(result.loudness, { programLoudness: null, normalizationGain: 0 });
  assert.ok(snrDb(pcm, result.pcm, 2048 * 2) > 30);
});

// A USAC stream decodes in full, and the MPEG-D loudness metadata is read
// with its configuration.
test('a USAC stream decodes and reports its MPEG-D loudness', { skip: !available && 'native binding not built' }, async () => {
  const service = new AacService();
  const stream = readFileSync(join(codec, 'tests/data/usac_music_48000_96000.loas'));
  const result = await service.decode(stream, { channels: 0 });
  assert.equal(result.objectType, 'usac');
  assert.equal(result.sampleRate, 48000);
  assert.equal(result.channels, 2);
  assert.equal(result.frameSize, 1024);
  assert.equal(result.errors, 0);
  assert.equal(result.concealedFrames, 0);
  assert.equal(result.pcm.length, 94 * 1024 * 2);
  assert.deepEqual(result.loudness, { programLoudness: -14.25, normalizationGain: -9.75 });

  const session = await service.openDecoder({ channels: 0, loudness: { targetLufs: -14.25 }, drcEffect: 'night', albumMode: true, drcBoost: 50, drcCut: 50 });
  const chunk = await service.decodeChunk(session, stream.subarray(0, 4000));
  assert.equal(chunk.objectType, 'usac');
  assert.deepEqual(chunk.loudness, { programLoudness: -14.25, normalizationGain: 0 });
  assert.equal(await service.closeDecoder(session), true);

  await assert.rejects(() => service.openDecoder({ drcEffect: 'loud' as never }), /drcEffect must be/);
  await assert.rejects(() => service.openDecoder({ loudness: { targetLufs: -5 } }));
});

// The xHE-AAC profile: a USAC stream the decoder reads back with the
// loudness info set the two-pass flow writes, the meter inside a session,
// and the standalone meter on EBU Tech 3341's first case.
test('xHE-AAC encodes with loudness metadata and the meter reads the input', { skip: !available && 'native binding not built' }, async () => {
  const service = new AacService();
  const sampleRate = 48000;
  const pcm = tones(188 * 1024, sampleRate, 2);
  const measured = await service.measureLoudness(pcm, sampleRate, 2);
  assert.ok(Number.isFinite(measured.integratedLkfs) && measured.rangeLu !== null);
  const stream = await service.encode(pcm, { sampleRate, channels: 2, bitrate: 96000, profile: 'xhe', loudnessInfo: measured, usac: { streamId: 3 } });
  assert.equal(stream[0], 0x56, 'LOAS by default');
  const result = await service.decode(stream, { channels: 0 });
  assert.equal(result.objectType, 'usac');
  assert.equal(result.errors, 0);
  assert.equal(result.sampleRate, sampleRate);
  assert.equal(result.streamChannels, 2);
  const declared = Math.round(measured.integratedLkfs * 4) / 4;
  assert.deepEqual(result.loudness, { programLoudness: declared, normalizationGain: -24 - declared });
  const gain = 10 ** (result.loudness.normalizationGain / 20);
  // 48 kbit/s per channel takes SBR: the HE-AAC delay.
  assert.ok(snrDb(pcm.map(x => x * gain), result.pcm, 5635 * 2) > 15, 'the programme decodes through the normalisation gain');

  const session = await service.openEncoder({ sampleRate, channels: 2, bitrate: 96000, profile: 'xhe', measureLoudness: true });
  assert.equal(session.profile, 'xhe');
  assert.equal(await service.encoderLoudness(session.id), null, 'no figures before any input');
  await service.encodeChunk(session.id, pcm);
  await service.flushEncoder(session.id);
  assert.deepEqual(await service.encoderLoudness(session.id), measured, 'the session meters the same frames');
  await service.closeEncoder(session.id);
  const plain = await service.openEncoder({ sampleRate, channels: 2, bitrate: 96000, profile: 'xhe' });
  assert.equal(await service.encoderLoudness(plain.id), null, 'no meter without the option');
  await service.closeEncoder(plain.id);

  const sine = new Float32Array(sampleRate * 2 * 2);
  for (let i = 0; i < sampleRate * 2; i++) {
    const v = 10 ** (-23 / 20) * Math.sin((2 * Math.PI * 997 * i) / sampleRate);
    sine[i * 2] = v;
    sine[i * 2 + 1] = v;
  }
  const ebu = await service.measureLoudness(sine, sampleRate, 2);
  assert.ok(Math.abs(ebu.integratedLkfs + 23) < 0.1, `-23 dBFS sine reads ${ebu.integratedLkfs} LKFS`);
  await assert.rejects(() => service.measureLoudness(sine, sampleRate, 7), /1 to 6 channels/);
  await assert.rejects(() => service.encode(pcm, { sampleRate, channels: 2, profile: 'lc', usac: { streamId: 1 } }), /usac options need the xhe profile/);
});

test('HE-AAC v2 codes a stereo signal as one channel and decodes it as two', { skip: !available && 'native binding not built' }, async () => {
  const service = new AacService();
  const pcm = tones(44100 * 2, 44100, 2);
  const session = await service.openEncoder({ sampleRate: 44100, channels: 2, bitrate: 32000, profile: 'hev2' });
  assert.deepEqual({ profile: session.profile, frameSize: session.frameSize, delay: session.delay }, { profile: 'hev2', frameSize: 2048, delay: 5635 });
  await service.closeEncoder(session.id);
  const stream = await service.encode(pcm, { sampleRate: 44100, channels: 2, bitrate: 32000, profile: 'hev2' });
  const result = await service.decode(stream, { channels: 0 });
  assert.equal(result.errors, 0);
  assert.equal(result.sampleRate, 44100);
  assert.equal(result.frameSize, 2048);
  assert.equal(result.channels, 2);
  assert.equal(result.streamChannels, 1);
  assert.equal(result.hasSbr, true);
  assert.equal(result.hasPs, true);
  assert.ok(snrDb(pcm, result.pcm, 5635 * 2) > 8);
});

test('streaming sessions match the one-shot result and are closed by id', { skip: !available && 'native binding not built' }, async () => {
  const service = new AacService();
  const pcm = tones(30000, 48000, 1);
  const whole = await service.encode(pcm, { sampleRate: 48000, channels: 1, bitrate: 64000 });
  const session = await service.openEncoder({ sampleRate: 48000, channels: 1, bitrate: 64000 });
  assert.deepEqual({ frameSize: session.frameSize, delay: session.delay }, { frameSize: 1024, delay: 2048 });
  const parts: Uint8Array[] = [];
  for (let pos = 0; pos < pcm.length; pos += 1234) parts.push(await service.encodeChunk(session.id, pcm.subarray(pos, pos + 1234)));
  parts.push(await service.flushEncoder(session.id));
  const streamed = Buffer.concat(parts);
  assert.deepEqual(streamed, Buffer.from(whole));
  assert.equal(await service.closeEncoder(session.id), true);
  assert.equal(await service.closeEncoder(session.id), false);
  await assert.rejects(() => service.encodeChunk(session.id, pcm), /does not exist/);

  const decoder = await service.openDecoder({ sampleRate: 48000, channels: 1 });
  const decoded: Float32Array[] = [];
  for (let pos = 0; pos < streamed.length; pos += 500) decoded.push((await service.decodeChunk(decoder, streamed.subarray(pos, pos + 500))).pcm);
  const total = decoded.reduce((n, d) => n + d.length, 0);
  assert.equal(total, (await service.decode(streamed, { sampleRate: 48000, channels: 1 })).pcm.length);
  assert.equal(await service.closeDecoder(decoder), true);
});

// The end of a stream drains what the limiter still holds, a seek forgets
// the audio before, and both keep the session open.
test('decoder sessions flush their tail and clear their history for a seek', { skip: !available && 'native binding not built' }, async () => {
  const service = new AacService();
  const pcm = tones(48000, 48000, 2);
  const stream = await service.encode(pcm, { sampleRate: 48000, channels: 2, bitrate: 128000 });
  const plain = (await service.decode(stream, { sampleRate: 48000, channels: 2 })).pcm;

  const decoder = await service.openDecoder({ sampleRate: 48000, channels: 2, limiter: { attackMs: 5 } });
  const body = await service.decodeChunk(decoder, stream);
  assert.equal(body.delay, 240);
  const tail = await service.flushDecoder(decoder);
  assert.equal(tail.pcm.length, 240 * 2, 'the look-ahead is drained');
  assert.equal(tail.sampleRate, 48000);
  const all = new Float32Array(body.pcm.length + tail.pcm.length);
  all.set(body.pcm, 0);
  all.set(tail.pcm, body.pcm.length);
  let worst = 0;
  for (let i = 0; i < plain.length; i++) worst = Math.max(worst, Math.abs(all[i + 240 * 2] - plain[i]));
  assert.ok(worst < 1e-6, `the limited output is the plain one delayed (${worst})`);
  assert.equal((await service.flushDecoder(decoder)).pcm.length, 0, 'nothing is left after the flush');

  // ADTS frames: the seek drops the half frame pending and the history.
  const frames: Uint8Array[] = [];
  for (let pos = 0; pos + 7 <= stream.length;) {
    const length = ((stream[pos + 3] & 3) << 11) | (stream[pos + 4] << 3) | (stream[pos + 5] >> 5);
    frames.push(stream.subarray(pos, pos + length));
    pos += length;
  }
  const seeking = await service.openDecoder({ sampleRate: 48000, channels: 2 });
  await service.decodeChunk(seeking, Buffer.concat(frames.slice(0, 10)));
  await service.decodeChunk(seeking, frames[10].subarray(0, frames[10].length >> 1));
  await service.clearDecoderHistory(seeking);
  const rest = Buffer.concat(frames.slice(25));
  const fromSeek = (await service.decodeChunk(seeking, rest)).pcm;
  const fresh = (await service.decode(rest, { sampleRate: 48000, channels: 2 })).pcm;
  assert.ok(fromSeek.length > 0);
  assert.deepEqual(fromSeek, fresh, 'after the seek the session decodes as a fresh one');
  assert.equal(await service.closeDecoder(seeking), true);
  assert.equal(await service.closeDecoder(decoder), true);
  await assert.rejects(() => service.flushDecoder(decoder), /does not exist/);
  await assert.rejects(() => service.clearDecoderHistory(decoder), /does not exist/);
});

test('unsupported options are rejected by the binding', { skip: !available && 'native binding not built' }, async () => {
  const service = new AacService();
  await assert.rejects(() => service.encode(new Float32Array(10), { sampleRate: 37000, channels: 2 }));
  await assert.rejects(() => service.openDecoder({ channels: 9 }));
  await assert.rejects(() => service.encode(new Float32Array(10), { sampleRate: 44100, channels: 1, profile: 'hev2' }));
});
